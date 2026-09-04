<?php
/*
MultiBuilder.php — Builds and manages SQLite-backed multi-field inverted indexes.
v3.5 (versioned with the engine — see Searcher::VERSION)

Produces the SAME per-field schema that lib/MultiSearch.php queries, so an
index built here is directly searchable with \MultiSearch\Searcher.

Why per-field tables: the first version of this class built a unified schema —
one postings table with a field column, plus doc_lengths and term_stats tables
that every query filtered with `WHERE field = ? AND term IN (...)`. Moving to
one WITHOUT ROWID table per field, keyed (term, doc_id), turned each term
lookup into a covering scan of exactly the rows wanted, with no field filter,
and measured ~10x faster on the Wikipedia corpus. That schema is what this
file builds; the unified layout is not readable by the Searcher and nothing
here produces it any more. The public API (addDocument / removeDocument /
rebuildStats / indexDocuments / introspection) survived the schema change
unchanged, apart from the optional $title/$opening parameters on
addDocument().

── Two ways to build ────────────────────────────────────────────────────────
  INCREMENTAL (instance API): new Builder($db) + addText()/addDocument() +
      rebuildStats(). WAL mode, one transaction per document — a live Searcher
      keeps reading its snapshot while you add/remove. For small corpora,
      incremental updates, and test fixtures.

  BULK (Builder::bulkBuild): one static call that streams an iterable of
      documents into a FRESH index file. Stage 1 appends postings to unindexed
      staging tables (heap appends are far cheaper than inserting into a
      WITHOUT ROWID B-tree in random term order); stage 2 fills each postings
      B-tree with ONE sorted INSERT...SELECT (sequential fill, no page splits),
      then computes stats. ~10x faster than the incremental path on large
      imports (283K Wikipedia articles / 48M postings in minutes, not hours).
      This strategy first lived in scripts/build-index.php, alongside a
      private copy of this class's schema DDL and stats SQL — two definitions
      of one schema with nothing enforcing agreement, the same drift risk
      the tokenizer once had (see the tokenization contract in
      MultiSearch.php). It moved here so one schema definition and one IDF
      implementation serve both paths; the script keeps only the
      Wikipedia-specific parsing.

── Schema (identical to data/wikipedia.db) ─────────────────────────
  postings_<field>  (term, doc_id, freq[, pos]) WITHOUT ROWID, PK(term, doc_id)
      One table per field — the table IS the field, no field column needed.
      PK(term, doc_id) makes every term lookup a covering B-tree scan.
      pos (default on; config 'positions' => false to omit): 0-based token
      positions as delta-varint blobs — enables adjacency-verified quoted
      phrases in the Searcher, measured +15.8% index size on the Wikipedia corpus.

  termstats_<field> (term, doc_freq, idf)       WITHOUT ROWID, PK(term)
      Document frequency + pre-computed BM25 IDF per term. IDF only depends
      on N and df, both fixed for a given index, so it's computed once in
      computeStats() — the ONLY implementation of the formula — instead of
      via log() on every scored term.

  doclens (doc_id, len_<field>...)              WITHOUT ROWID, PK(doc_id)
      One row per doc with token counts for all fields (columns added
      dynamically per field). Used by BM25/DFR length normalization.
      One query fetches all fields' lengths.

  field_stats (field, total_docs, total_length)
      Corpus aggregates. total_length/total_docs = avgdl for BM25.

  unique_terms (term, len, fc)                  + INDEX (fc, len)
      Deduplicated term list for fuzzy matching. fc = first character,
      len = LENGTH(term) — both used by the fuzzy prefilter.

  documents (doc_id, title, opening)
      Optional display title + opening text per doc. The searcher returns
      title per hit and builds snippets from opening. doc_id stays opaque.

  meta (key, value)
      Provenance: schema_version, tokenizer_name, builder, built_at,
      positions, doc_id_type (+ anything bulkBuild's caller adds).

  doc_id type (config 'doc_id_type'): TEXT (default — any opaque key:
      UUIDs, URLs, slugs) or INTEGER (compact varint storage, ~4 bytes less
      per posting row for 8-digit ids — the Wikipedia index uses page_id).
      Fixed at creation; existing indexes are read from their schema.

── Usage ─────────────────────────────────────────────────────────────────────
    $b = new \MultiSearch\Builder('index.db');

    // Tokens with repeats → term frequency; pass unique tokens for binary match
    $b->addDocument('doc1', [
        'title' => ['php', 'web', 'programming'],
        'body'  => ['php', 'php', 'web', 'tutorial', 'php'],
    ], 'Display title', 'Optional opening text used for snippets.');

    $b->rebuildStats();   // REQUIRED before searching

    // Or bulk helper (calls rebuildStats automatically):
    $b->indexDocuments(['doc1' => ['title' => [...], 'body' => [...]], ...]);

    // Large corpora: stream into a fresh file (see bulkBuild() for options)
    \MultiSearch\Builder::bulkBuild('index.db', $generator, ['doc_id_type' => 'INTEGER']);
*/

namespace MultiSearch;

class Builder
{
    private \PDO $db;

    /** Fields that already have postings_/termstats_ tables + doclens column. */
    private array $fields = [];

    /** Optional text tokenizer for addText(); null = library default. */
    private ?\Closure $tokenizer = null;

    /** Whether postings rows carry a position blob (see resolvePositions). */
    private bool $positions = true;

    /** SQL type of doc_id columns — 'TEXT' or 'INTEGER' (see header). */
    private string $docIdType = 'TEXT';

    /** New index files use 16 KB pages: shallower B-trees on the 48M-row
     *  postings tables, measured on the Wikipedia build; no measured downside
     *  on small ones. SQLite fixes page_size once the first table exists,
     *  hence "new files only". */
    private const PAGE_SIZE = 16384;

    // $config keys:
    //   'tokenizer'       callable used by addText(); null = library default.
    //   'tokenizer_name'  recorded in the meta table (provenance).
    //   'fold_diacritics' bool — addText() tokenizes with diacritic folding
    //                     and records Searcher::TOKENIZER_FOLDED in meta.
    //                     Ignored when a custom 'tokenizer' is given (that
    //                     callable owns normalization). On an EXISTING index
    //                     with no explicit setting, the meta row decides —
    //                     the same "existing schema wins" rule positions
    //                     follow, for the same reason: mixing folded and
    //                     unfolded rows in one vocabulary is a wrong-results
    //                     bug (são and sao would coexist, each finding half).
    //   'positions'       bool — store token positions as delta-varint blobs
    //                     on postings rows (adjacency-verified phrases in the
    //                     Searcher). Default ON for new indexes; existing
    //                     indexes follow their schema.
    //   'doc_id_type'     'TEXT' | 'INTEGER', new indexes only.
    public function __construct(string $dbPath, array $config = [])
    {
        $fresh = !is_file($dbPath) || filesize($dbPath) === 0;
        $this->db = new \PDO("sqlite:$dbPath");
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        if ($fresh) $this->db->exec("PRAGMA page_size = " . self::PAGE_SIZE);
        $this->db->exec("PRAGMA journal_mode = WAL");   // readers stay live during writes
        $this->db->exec("PRAGMA synchronous  = NORMAL");
        $this->db->exec("PRAGMA busy_timeout = 5000");   // wait, don't throw, on lock contention
        $this->docIdType = $fresh
            ? self::checkDocIdType($config['doc_id_type'] ?? 'TEXT')
            : self::detectDocIdType($this->db);
        $this->db->exec(self::coreSchemaSql($this->docIdType));
        $this->discoverFields();
        $this->resolvePositions(array_key_exists('positions', $config) ? (bool)$config['positions'] : null);

        $tokName = $config['tokenizer_name'] ?? null;
        if (isset($config['tokenizer'])) {
            $this->tokenizer = \Closure::fromCallable($config['tokenizer']);
        } else {
            $fold = $config['fold_diacritics'] ?? null;
            if ($fold === null && !$fresh) {
                $fold = self::readMetaValue($this->db, 'tokenizer_name') === Searcher::TOKENIZER_FOLDED;
            }
            if ($fold) {
                self::needSearcher();
                $this->tokenizer = fn(string $t): array => Searcher::tokenize($t, true);
                $tokName ??= Searcher::TOKENIZER_FOLDED;
            }
        }
        $this->writeMeta($tokName);
    }

    /** One meta value, or null (table/row absent). */
    private static function readMetaValue(\PDO $db, string $key): ?string
    {
        try {
            $stmt = $db->prepare("SELECT value FROM meta WHERE key = ?");
            $stmt->execute([$key]);
            $v = $stmt->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (\PDOException $e) {
            return null;
        }
    }

    /**
     * Resolve the positions setting against reality. An existing index's
     * schema WINS — mixing positional and position-less rows in one table
     * would make phrase verification silently skip the old rows (a NULL blob
     * can never satisfy adjacency), which is a wrong-results bug, not a
     * degraded mode. So an explicit config that contradicts an existing
     * schema throws: the honest fix is a rebuild, not an ALTER TABLE.
     */
    private function resolvePositions(?bool $explicit): void
    {
        if (empty($this->fields)) {
            $this->positions = $explicit ?? true;   // fresh index: default ON
            return;
        }
        $cols = $this->db->query("PRAGMA table_info(postings_{$this->fields[0]})")
                         ->fetchAll(\PDO::FETCH_COLUMN, 1);
        $schemaHas = in_array('pos', $cols, true);
        if ($explicit !== null && $explicit !== $schemaHas) {
            throw new \InvalidArgumentException(
                "positions=" . var_export($explicit, true) . " conflicts with the existing index schema ("
                . ($schemaHas ? 'has' : 'lacks') . " pos column). Rebuild the index to change position storage."
            );
        }
        $this->positions = $schemaHas;
    }

    /**
     * Provenance rows — the index file outlives memory of how it was
     * made; these let it answer "which schema generation / tokenizer rules /
     * when?" on its own (sqlite3 db "SELECT * FROM meta"). PROVENANCE ONLY —
     * nothing enforces it at query time, and no derivable facts (counts live
     * in field_stats).
     * Best-effort honesty: this class receives PRE-TOKENIZED arrays via
     * addDocument(), so it cannot know the tokenizer and does NOT pretend to —
     * 'tokenizer_name' is written only when the caller declares it (config)
     * or when addText() tokenizes internally with the library default. An
     * absent row means "caller-tokenized, unrecorded", which beats a
     * fabricated one. INSERT OR IGNORE keeps the original built_at when
     * reopening an existing index.
     */
    private function writeMeta(?string $tokenizerName): void
    {
        $ins = $this->db->prepare("INSERT OR IGNORE INTO meta (key, value) VALUES (?, ?)");
        $ins->execute(['schema_version', '1']);
        $ins->execute(['builder', 'lib/MultiBuilder.php']);
        $ins->execute(['built_at', date('c')]);
        $ins->execute(['positions', $this->positions ? '1' : '0']);
        $ins->execute(['doc_id_type', $this->docIdType]);
        if ($tokenizerName !== null) {
            // Caller-declared name may legitimately replace an earlier one.
            $this->db->prepare("INSERT OR REPLACE INTO meta (key, value) VALUES ('tokenizer_name', ?)")
                     ->execute([$tokenizerName]);
        }
    }

    // -------------------------------------------------------------------------
    // Schema — ONE definition, used by the incremental path and bulkBuild()
    // -------------------------------------------------------------------------

    private static function checkDocIdType(string $type): string
    {
        $type = strtoupper($type);
        if ($type !== 'TEXT' && $type !== 'INTEGER') {
            throw new \InvalidArgumentException("doc_id_type must be 'TEXT' or 'INTEGER', got '$type'");
        }
        return $type;
    }

    /** Existing index: the doclens PK column's declared type is the truth. */
    private static function detectDocIdType(\PDO $db): string
    {
        foreach ($db->query("PRAGMA table_info(doclens)")->fetchAll(\PDO::FETCH_ASSOC) as $col) {
            if ($col['name'] === 'doc_id') {
                return strtoupper($col['type']) === 'INTEGER' ? 'INTEGER' : 'TEXT';
            }
        }
        return 'TEXT';   // no doclens yet (empty file opened earlier): library default
    }

    /** Field-independent tables. Idempotent (IF NOT EXISTS). */
    private static function coreSchemaSql(string $idType): string
    {
        return "
            CREATE TABLE IF NOT EXISTS doclens (
                doc_id $idType NOT NULL PRIMARY KEY
            ) WITHOUT ROWID;

            CREATE TABLE IF NOT EXISTS field_stats (
                field        TEXT    NOT NULL PRIMARY KEY,
                total_docs   INTEGER NOT NULL DEFAULT 0,
                total_length INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS unique_terms (
                term TEXT    PRIMARY KEY,
                len  INTEGER NOT NULL,
                fc   TEXT    NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_ut_fc_len ON unique_terms (fc, len);

            CREATE TABLE IF NOT EXISTS documents (
                doc_id  $idType PRIMARY KEY,
                title   TEXT,
                opening TEXT
            );

            CREATE TABLE IF NOT EXISTS meta (
                key   TEXT PRIMARY KEY,
                value TEXT
            );
        ";
    }

    /**
     * Per-field tables. pos = delta-varint token positions
     * (Searcher::encodePositions); present only on positional indexes — the
     * Searcher introspects for it.
     */
    private static function fieldSchemaSql(string $field, bool $positions, string $idType): string
    {
        $posCol = $positions ? "pos    BLOB," : "";
        return "
            CREATE TABLE IF NOT EXISTS postings_$field (
                term   TEXT    NOT NULL,
                doc_id $idType NOT NULL,
                freq   INTEGER NOT NULL DEFAULT 1,
                $posCol
                PRIMARY KEY (term, doc_id)
            ) WITHOUT ROWID;

            CREATE TABLE IF NOT EXISTS termstats_$field (
                term     TEXT    NOT NULL PRIMARY KEY,
                doc_freq INTEGER NOT NULL DEFAULT 0,
                idf      REAL    NOT NULL DEFAULT 0.0
            ) WITHOUT ROWID;
        ";
    }

    /** Add the field's length column to the merged doclens table (idempotent). */
    private static function ensureLenColumn(\PDO $db, string $field): void
    {
        $cols = $db->query("PRAGMA table_info(doclens)")->fetchAll(\PDO::FETCH_COLUMN, 1);
        if (!in_array("len_$field", $cols, true)) {
            $db->exec("ALTER TABLE doclens ADD COLUMN len_$field INTEGER NOT NULL DEFAULT 0");
        }
    }

    /** Re-discover per-field tables when opening an existing index. */
    private function discoverFields(): void
    {
        $stmt = $this->db->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'postings\\_%' ESCAPE '\\'"
        );
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $this->fields[] = substr($table, strlen('postings_'));
        }
    }

    /**
     * Field names become part of SQL identifiers (postings_<field>, len_<field>),
     * so they must be strictly validated — this is the injection boundary.
     */
    private static function checkField(string $field): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $field)) {
            throw new \InvalidArgumentException(
                "Invalid field name '$field': use lowercase letters, digits, underscore"
            );
        }
        return $field;
    }

    private function ensureField(string $field): void
    {
        $field = self::checkField($field);
        if (in_array($field, $this->fields, true)) return;
        $this->db->exec(self::fieldSchemaSql($field, $this->positions, $this->docIdType));
        self::ensureLenColumn($this->db, $field);
        $this->fields[] = $field;
    }

    /**
     * Term frequency + 0-based positions from an ORDERED token list — the one
     * place that defines what a postings row holds. Positions are implicit in
     * the array order (why addDocument's contract asks for ordered tokens).
     *   Earlier (frequency only, no positions):
     *   $freqs = array_count_values(array_map('strval', $tokens));
     */
    private static function termCounts(array $tokens, bool $positions): array
    {
        $freqs = [];
        $poss  = [];
        $i = 0;
        foreach ($tokens as $tok) {
            $tok = (string)$tok;
            $freqs[$tok] = ($freqs[$tok] ?? 0) + 1;
            if ($positions) $poss[$tok][] = $i;
            $i++;
        }
        return [$freqs, $poss];
    }

    /** Searcher provides the tokenizer and the position codec; load it lazily
     *  so pre-tokenized, position-less callers can use this class alone. */
    private static function needSearcher(): void
    {
        if (!class_exists(Searcher::class)) {
            require_once __DIR__ . '/MultiSearch.php';
        }
    }

    // -------------------------------------------------------------------------
    // Document management
    // -------------------------------------------------------------------------

    /**
     * Add or replace a document in the index.
     *
     * $fields format: ['field_name' => ['token1', 'token2', 'token1', ...]]
     * Repeated tokens are counted as higher term frequency (good for BM25).
     * Pass unique tokens for binary matching.
     *
     * $title: optional display title stored in the documents table; the
     * searcher returns it per hit. doc_id itself is opaque — pass any unique
     * key (int, UUID, URL); duplicate titles across documents are fine.
     *
     * $opening: optional plain-text opening/summary stored in the documents
     * table; the searcher uses it for result snippets.
     *
     * (Parameter order is title, then opening. An earlier signature had
     * opening first — title was added later — so title-only callers had to
     * write `null, 'Title'`; the order was swapped once both existed.)
     *
     * NOTE: Does NOT update stats. Call rebuildStats() when done making changes.
     */
    public function addDocument(string $docId, array $fields, ?string $title = null, ?string $opening = null): void
    {
        foreach (array_keys($fields) as $f) {
            $this->ensureField((string)$f);
        }
        if ($this->positions) self::needSearcher();

        $this->db->beginTransaction();
        try {
            // Remove any existing entries for this doc across ALL known fields —
            // a re-added doc may have dropped a field it previously had.
            foreach ($this->fields as $f) {
                $this->db->prepare("DELETE FROM postings_$f WHERE doc_id = ?")
                         ->execute([$docId]);
            }
            $this->db->prepare("DELETE FROM doclens   WHERE doc_id = ?")->execute([$docId]);
            $this->db->prepare("DELETE FROM documents WHERE doc_id = ?")->execute([$docId]);

            $lenCols = ['doc_id' => $docId];
            foreach ($fields as $field => $tokens) {
                if (empty($tokens)) continue;
                $field = self::checkField((string)$field);

                $stmtPost = $this->db->prepare($this->positions
                    ? "INSERT INTO postings_$field (term, doc_id, freq, pos) VALUES (?, ?, ?, ?)"
                    : "INSERT INTO postings_$field (term, doc_id, freq) VALUES (?, ?, ?)"
                );
                [$freqs, $poss] = self::termCounts($tokens, $this->positions);
                foreach ($freqs as $term => $freq) {
                    $stmtPost->bindValue(1, (string)$term);
                    $stmtPost->bindValue(2, $docId);
                    $stmtPost->bindValue(3, $freq, \PDO::PARAM_INT);
                    if ($this->positions) {
                        // PARAM_LOB: bind as a real BLOB — varint bytes can
                        // contain NULs, which must survive round-trip.
                        $stmtPost->bindValue(4, Searcher::encodePositions($poss[$term]), \PDO::PARAM_LOB);
                    }
                    $stmtPost->execute();
                }
                // Document length = total token count (with repetitions)
                $lenCols["len_$field"] = count($tokens);
            }

            if (count($lenCols) > 1) {
                $names = implode(', ', array_keys($lenCols));
                $ph    = implode(', ', array_fill(0, count($lenCols), '?'));
                $this->db->prepare("INSERT INTO doclens ($names) VALUES ($ph)")
                         ->execute(array_values($lenCols));
            }

            if (($opening !== null && $opening !== '') || ($title !== null && $title !== '')) {
                $this->db->prepare("INSERT OR REPLACE INTO documents (doc_id, title, opening) VALUES (?, ?, ?)")
                         ->execute([$docId, $title, $opening]);
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Convenience: add a document from RAW TEXT per field — tokenizes
     * internally with the configured tokenizer or the library default, so
     * callers can't accidentally tokenize differently than the query side.
     * Records the default tokenizer's name in meta when it's the one used
     * (truthful, unlike addDocument() which can't know how tokens were made).
     *
     * $fieldTexts format: ['field_name' => 'raw text ...', ...]
     */
    public function addText(string $docId, array $fieldTexts, ?string $title = null, ?string $opening = null): void
    {
        if ($this->tokenizer === null) {
            self::needSearcher();
            $this->db->prepare("INSERT OR IGNORE INTO meta (key, value) VALUES ('tokenizer_name', ?)")
                     ->execute([Searcher::TOKENIZER_DEFAULT]);
        }
        $tok = $this->tokenizer ?? [Searcher::class, 'tokenize'];
        $this->addDocument(
            $docId,
            array_map(fn($text) => $tok((string)$text), $fieldTexts),
            $title,
            $opening
        );
    }

    /**
     * Remove a document from the index.
     * NOTE: Does NOT update stats. Call rebuildStats() when done.
     */
    public function removeDocument(string $docId): void
    {
        $this->db->beginTransaction();
        try {
            foreach ($this->fields as $f) {
                $this->db->prepare("DELETE FROM postings_$f WHERE doc_id = ?")
                         ->execute([$docId]);
            }
            $this->db->prepare("DELETE FROM doclens   WHERE doc_id = ?")->execute([$docId]);
            $this->db->prepare("DELETE FROM documents WHERE doc_id = ?")->execute([$docId]);
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Recompute termstats_<field>, field_stats, and unique_terms from the
     * current postings. Call after any addDocument / removeDocument operations
     * and before searching.
     */
    public function rebuildStats(): void
    {
        self::computeStats($this->db, $this->fields);
    }

    /**
     * THE stats implementation, shared by rebuildStats() and bulkBuild().
     * There used to be three copies of the IDF formula — here, in the bulk
     * build script, and a fallback inside the Searcher — with nothing keeping
     * them equal; this is now the only one.
     *
     *   idf = log((N + 0.5) / (df + 0.5) + 1)      (BM25+, N = docs with the field)
     *
     * The Searcher reads this column and has no formula of its own — change it
     * here and rebuild. SQLite may lack math functions (a compile-time
     * option), so PHP's log() is exposed as php_log. COUNT(*) per term equals
     * the distinct doc count because (term, doc_id) is the postings PK, and
     * GROUP BY term walks that PK in order — no sort needed.
     */
    private static function computeStats(\PDO $db, array $fields, ?callable $progress = null): void
    {
        $db->sqliteCreateFunction('php_log', 'log', 1);

        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $db->exec("DELETE FROM field_stats");
            $db->exec("DELETE FROM unique_terms");

            foreach ($fields as $f) {
                $t0 = microtime(true);
                // Corpus aggregates: docs that have this field, and total tokens
                $db->exec("
                    INSERT INTO field_stats (field, total_docs, total_length)
                    SELECT '$f', COUNT(*), COALESCE(SUM(len_$f), 0)
                    FROM doclens WHERE len_$f > 0
                ");
                $N = (int)$db->query("SELECT total_docs FROM field_stats WHERE field = '$f'")->fetchColumn();

                $db->exec("DELETE FROM termstats_$f");
                $db->exec("
                    INSERT INTO termstats_$f (term, doc_freq, idf)
                    SELECT term, COUNT(*), php_log(($N + 0.5) / (COUNT(*) + 0.5) + 1)
                    FROM postings_$f
                    GROUP BY term
                ");

                // Deduplicated term list for the fuzzy-matching prefilter:
                // LENGTH + first char (the prefilter's two range keys).
                $db->exec("
                    INSERT OR IGNORE INTO unique_terms (term, len, fc)
                    SELECT term, LENGTH(term), SUBSTR(term, 1, 1) FROM termstats_$f
                ");
                if ($progress) {
                    $progress('stats', ['field' => $f, 'docs' => $N,
                        'terms' => (int)$db->query("SELECT COUNT(*) FROM termstats_$f")->fetchColumn(),
                        'elapsed' => microtime(true) - $t0]);
                }
            }
            if ($own) $db->commit();
        } catch (\Exception $e) {
            if ($own) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Bulk index documents and rebuild stats in one call.
     *
     * $docs format:
     *   ['doc_id' => ['field' => ['token', ...], ...], ...]
     */
    public function indexDocuments(array $docs): void
    {
        foreach ($docs as $docId => $fields) {
            $this->addDocument((string)$docId, $fields);
        }
        $this->rebuildStats();
    }

    // -------------------------------------------------------------------------
    // Bulk build
    // -------------------------------------------------------------------------

    /**
     * Build a FRESH index from a stream of documents — the fast path for
     * large corpora (see header: staging tables + sorted insert).
     *
     * $docs: any iterable (a generator keeps memory flat for multi-GB dumps)
     *   yielding [docId, fields, ?title, ?opening] where fields is
     *   ['field' => 'raw text'] (tokenized here) or ['field' => [tokens...]]
     *   (pre-tokenized, ordered). Duplicate doc ids: first wins, the rest are
     *   counted in 'skipped' — a duplicate would violate the postings PK at the
     *   sorted insert and corrupt doclens consistency.
     *
     * $config:
     *   positions      bool     store token positions           default true
     *   doc_id_type    string   'TEXT' | 'INTEGER'               default 'TEXT'
     *   tokenizer      callable text -> tokens; default Searcher::tokenize
     *   fold_diacritics bool    default tokenizer folds diacritics
     *                           (café → cafe); ignored with a custom
     *                           tokenizer                       default false
     *   tokenizer_name string   recorded in meta (default name when the
     *                           default tokenizer is used)
     *   meta           array    extra provenance rows (e.g. source_dump);
     *                           may override the base rows, e.g. 'builder'
     *   batch_size     int      docs per staging transaction   default 2000
     *   on_progress    callable(string $stage, array $info) — 'parse' every
     *                           batch (docs, skipped, elapsed), 'postings' and
     *                           'stats' per field (rows/terms, elapsed),
     *                           'vacuum' (elapsed)
     *
     * Refuses to overwrite an existing file — delete it first, explicitly.
     * The file is written with journal_mode OFF (no crash safety: a failed
     * build is rebuilt from scratch) and EXCLUSIVE locking; it is not
     * searchable until this returns. Returns a summary:
     *   ['docs' => int, 'skipped' => int, 'fields' => [f => ['postings' => int,
     *    'terms' => int, 'docs' => int]], 'bytes' => int, 'elapsed' => float]
     */
    public static function bulkBuild(string $dbPath, iterable $docs, array $config = []): array
    {
        if (is_file($dbPath) && filesize($dbPath) > 0) {
            throw new \InvalidArgumentException(
                "bulkBuild refuses to overwrite '$dbPath' — delete it first"
            );
        }
        $positions = array_key_exists('positions', $config) ? (bool)$config['positions'] : true;
        $idType    = self::checkDocIdType($config['doc_id_type'] ?? 'TEXT');
        $batchSize = max(1, (int)($config['batch_size'] ?? 2000));
        $progress  = $config['on_progress'] ?? null;
        $tokenizer = isset($config['tokenizer']) ? \Closure::fromCallable($config['tokenizer']) : null;
        $tokName   = $config['tokenizer_name'] ?? null;
        if ($tokenizer === null) {
            self::needSearcher();
            if (!empty($config['fold_diacritics'])) {
                $tokenizer = fn(string $t): array => Searcher::tokenize($t, true);
                $tokName ??= Searcher::TOKENIZER_FOLDED;
            } else {
                $tokenizer = \Closure::fromCallable([Searcher::class, 'tokenize']);
                $tokName ??= Searcher::TOKENIZER_DEFAULT;
            }
        }
        if ($positions) self::needSearcher();

        $t0 = microtime(true);
        $db = new \PDO("sqlite:$dbPath");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec("PRAGMA page_size    = " . self::PAGE_SIZE);
        $db->exec("PRAGMA journal_mode = OFF");       // no crash safety — rebuild from scratch
        $db->exec("PRAGMA synchronous  = OFF");
        $db->exec("PRAGMA cache_size   = -512000");   // 512 MB page cache
        $db->exec("PRAGMA locking_mode = EXCLUSIVE");
        // temp_store stays on disk: stage 2 sorts tens of millions of rows;
        // in-memory temp would risk OOM, and the external merge sort is fast.
        $db->exec(self::coreSchemaSql($idType));

        // ── Stage 1: stream into unindexed staging tables ──────────────────
        // raw_<field> are plain heap tables, no PK/index — the cheapest
        // possible append. Created on first sight of each field, like
        // ensureField(). The position blob rides through to the sorted insert.
        $fields   = [];
        $stmtRaw  = [];
        $posCol   = $positions ? ", pos BLOB" : "";
        $stmtLens = null;   // prepared lazily: its column list grows with fields
        $stmtDoc  = $db->prepare("INSERT INTO documents (doc_id, title, opening) VALUES (?, ?, ?)");
        $count = 0; $skipped = 0;
        $seen  = [];

        $db->exec("BEGIN");
        foreach ($docs as $doc) {
            [$docId, $fieldData] = $doc;
            $title   = $doc[2] ?? null;
            $opening = $doc[3] ?? null;
            $key = (string)$docId;
            if ($key === '' || isset($seen[$key])) { $skipped++; continue; }
            $seen[$key] = true;

            $lens = [];
            foreach ($fieldData as $field => $data) {
                $tokens = is_array($data) ? $data : $tokenizer((string)$data);
                if (empty($tokens)) continue;
                $field = self::checkField((string)$field);
                if (!isset($stmtRaw[$field])) {
                    $db->exec("CREATE TABLE raw_$field (term TEXT NOT NULL, doc_id $idType NOT NULL, freq INTEGER NOT NULL$posCol)");
                    $stmtRaw[$field] = $db->prepare($positions
                        ? "INSERT INTO raw_$field (term, doc_id, freq, pos) VALUES (?, ?, ?, ?)"
                        : "INSERT INTO raw_$field (term, doc_id, freq) VALUES (?, ?, ?)");
                    self::ensureLenColumn($db, $field);
                    $fields[] = $field;
                    $stmtLens = null;
                }
                $ins = $stmtRaw[$field];
                [$freqs, $poss] = self::termCounts($tokens, $positions);
                $idParam = is_int($docId) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
                foreach ($freqs as $term => $freq) {
                    $ins->bindValue(1, (string)$term);
                    $ins->bindValue(2, $docId, $idParam);
                    $ins->bindValue(3, $freq, \PDO::PARAM_INT);
                    if ($positions) {
                        $ins->bindValue(4, Searcher::encodePositions($poss[$term]), \PDO::PARAM_LOB);
                    }
                    $ins->execute();
                }
                $lens["len_$field"] = count($tokens);
            }

            if (!empty($lens)) {
                if ($stmtLens === null) {
                    $cols = array_map(fn($f) => "len_$f", $fields);
                    $stmtLens = $db->prepare("INSERT INTO doclens (doc_id, " . implode(', ', $cols) . ") VALUES (?"
                        . str_repeat(', ?', count($cols)) . ")");
                }
                $row = [$docId];
                foreach ($fields as $f) $row[] = $lens["len_$f"] ?? 0;
                $stmtLens->execute($row);
            }
            if (($title !== null && $title !== '') || ($opening !== null && $opening !== '')) {
                $stmtDoc->execute([$docId, $title, $opening]);
            }

            $count++;
            if ($count % $batchSize === 0) {
                $db->exec("COMMIT");
                if ($progress) $progress('parse', ['docs' => $count, 'skipped' => $skipped,
                    'elapsed' => microtime(true) - $t0]);
                $db->exec("BEGIN");
            }
        }
        $db->exec("COMMIT");
        if ($progress) $progress('parse', ['docs' => $count, 'skipped' => $skipped,
            'elapsed' => microtime(true) - $t0, 'done' => true]);
        unset($seen);

        // ── Stage 2: sorted insert into the real B-trees, then stats ───────
        $summary = [];
        foreach ($fields as $f) {
            $t1 = microtime(true);
            $db->exec(self::fieldSchemaSql($f, $positions, $idType));
            $posSel = $positions ? ", pos" : "";
            // ORDER BY the PK so the B-tree fills sequentially — no page splits.
            $db->exec("
                INSERT INTO postings_$f (term, doc_id, freq$posSel)
                SELECT term, doc_id, freq$posSel FROM raw_$f ORDER BY term, doc_id
            ");
            $db->exec("DROP TABLE raw_$f");
            $rows = (int)$db->query("SELECT COUNT(*) FROM postings_$f")->fetchColumn();
            $summary[$f] = ['postings' => $rows];
            if ($progress) $progress('postings', ['field' => $f, 'rows' => $rows,
                'elapsed' => microtime(true) - $t1]);
        }
        self::computeStats($db, $fields, function (string $stage, array $info) use (&$summary, $progress) {
            $summary[$info['field']]['terms'] = $info['terms'];
            $summary[$info['field']]['docs']  = $info['docs'];
            if ($progress) $progress($stage, $info);
        });

        // Provenance: base rows, then the caller's (which may override).
        $meta = [
            'schema_version' => '1',
            'tokenizer_name' => $tokName,
            'builder'        => 'lib/MultiBuilder.php (bulkBuild)',
            'built_at'       => date('c'),
            'positions'      => $positions ? '1' : '0',
            'doc_id_type'    => $idType,
        ] ;
        foreach (($config['meta'] ?? []) as $k => $v) $meta[$k] = (string)$v;
        $ins = $db->prepare("INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)");
        foreach ($meta as $k => $v) {
            if ($v !== null) $ins->execute([$k, $v]);
        }

        $t2 = microtime(true);
        $db->exec("VACUUM");   // reclaim the dropped staging tables' pages
        if ($progress) $progress('vacuum', ['elapsed' => microtime(true) - $t2]);
        $db = null;

        return [
            'docs'    => $count,
            'skipped' => $skipped,
            'fields'  => $summary,
            'bytes'   => filesize($dbPath),
            'elapsed' => microtime(true) - $t0,
        ];
    }

    // -------------------------------------------------------------------------
    // Introspection
    // -------------------------------------------------------------------------

    /**
     * Return all distinct terms indexed for a field.
     */
    public function getTermList(string $field): array
    {
        $field = self::checkField($field);
        $stmt = $this->db->query("SELECT term FROM termstats_$field ORDER BY term");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** Whether this index stores token positions. */
    public function hasPositions(): bool
    {
        return $this->positions;
    }

    /** 'TEXT' or 'INTEGER' — the doc_id column type of this index. */
    public function docIdType(): string
    {
        return $this->docIdType;
    }

    /**
     * Return all fields that have been indexed.
     */
    public function getFields(): array
    {
        $fields = $this->fields;
        sort($fields);
        return $fields;
    }

    /**
     * Return basic stats for each field.
     * ['field' => ['total_docs' => int, 'total_length' => int, 'unique_terms' => int]]
     */
    public function getStats(): array
    {
        $out = [];
        foreach ($this->getFields() as $f) {
            $row = $this->db->query(
                "SELECT total_docs, total_length FROM field_stats WHERE field = '$f'"
            )->fetch(\PDO::FETCH_ASSOC) ?: ['total_docs' => 0, 'total_length' => 0];
            $terms = (int)$this->db->query("SELECT COUNT(*) FROM termstats_$f")->fetchColumn();
            $out[$f] = [
                'total_docs'   => (int)$row['total_docs'],
                'total_length' => (int)$row['total_length'],
                'unique_terms' => $terms,
            ];
        }
        return $out;
    }
}
