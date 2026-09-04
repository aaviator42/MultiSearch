<?php
/*
OewnSynonyms.php — Open English WordNet synonym provider.
v3.5 (versioned with the engine — see Searcher::VERSION)

Builds synonym groups in the exact format Searcher's 'synonyms' option takes:
    [['word', 'syn1', 'syn2'], ...]

── Why this is a SEPARATE class and not part of Searcher ─────────────────────
The engine takes synonyms as plain data on purpose: that boundary keeps the
corpus-agnostic core free of any coupling to a specific lexical database
(OEWN's schema, its sense ordering, English, the CC-BY-4.0 attribution).
A different corpus swaps in a different provider — or none — without the
Searcher knowing. See data/oewn.db, built by scripts/build-oewn.php.

── Why a class at all ─────────────────────────────────────────────────────────
These lookups started life as three plain functions inside the demo's
index.php, then were copy-pasted into the test suite so it could "mirror
index.php" — ~55 duplicated lines where every synonym-lookup fix had to be
made twice, and one place inevitably lagged. One class, required by both,
ended that: the UI and the test suite now expand queries through identical
code.

── Usage ─────────────────────────────────────────────────────────────────────
    $oewn = new \MultiSearch\OewnSynonyms('data/oewn.db');
    $groups = $oewn->ready()
        ? $oewn->groupsFor(\MultiSearch\OewnSynonyms::queryWords($query), 1)
        : [];
    $searcher->search($query, ['synonyms' => $groups]);
*/

namespace MultiSearch;

class OewnSynonyms
{
    private ?\PDO $db = null;

    public function __construct(private string $dbPath)
    {
    }

    /** True when the synonym DB exists and looks non-trivial. */
    public function ready(): bool
    {
        return file_exists($this->dbPath) && filesize($this->dbPath) > 65536;
    }

    /**
     * Synonym groups for the given words, most-common senses first.
     * $maxSenses: 1 = precise (primary sense only), 3 = broad.
     * Each group is [word, synonym, ...]; words with no synonyms produce no
     * group. Output matches Searcher's 'synonyms' option format.
     */
    public function groupsFor(array $words, int $maxSenses = 1): array
    {
        if (!$this->ready()) return [];
        // members LIKE '%,%' — skip single-member synsets (no actual synonyms)
        $stmt = $this->db()->prepare("
            SELECT s.members FROM synsets s
            JOIN word_synsets ws ON ws.synset_id = s.id
            WHERE ws.word = ? AND s.members LIKE '%,%'
            ORDER BY ws.sense_order LIMIT ?
        ");
        $groups = [];
        foreach (array_unique($words) as $word) {
            $word = strtolower(trim((string)$word));
            if ($word === '') continue;
            $stmt->execute([$word, $maxSenses]);
            $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            if (empty($rows)) continue;
            $syns = [];
            foreach ($rows as $members) {
                foreach (explode(',', $members) as $m) {
                    $m = trim($m);
                    if ($m !== '' && $m !== $word) $syns[] = $m;
                }
            }
            $syns = array_values(array_unique($syns));
            if (!empty($syns)) $groups[] = array_merge([$word], $syns);
        }
        return $groups;
    }

    /** Lazy read-only open — oewn.db is a build artifact this class never
     *  writes. SQLITE_OPEN_READONLY makes the OS enforce that, the same
     *  rationale as the Searcher's index open (a read-write handle can
     *  silently modify the file, e.g. by switching its journal mode). */
    private function db(): \PDO
    {
        if ($this->db === null) {
            $this->db = new \PDO('sqlite:' . $this->dbPath, null, null,
                [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
            $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        return $this->db;
    }

    /**
     * Verifier for RISKY stem-rule candidates — callable(word, root): bool,
     * true when OEWN records a derivational/pertainym link between the two
     * (quickly->quick yes; summer->sum no). Used as the Searcher profile's
     * 'stem_verifier'. Returns null when the DB is unavailable (ready() is
     * false) — the Searcher then simply keeps the risky rules disabled.
     *
     * An oewn.db WITHOUT the derivations table is treated as a broken build
     * and throws on first use. An earlier version returned null in that case
     * too, which quietly degraded stemming instead of saying so — a missing
     * table is a build error, not an absent feature; rebuild with
     * scripts/build-oewn.php.
     *
     * NOTE: only DERIVATIONAL candidates should be checked against this —
     * inflection (plurals, -ing/-ed) is NOT in WordNet's derivation links
     * (wolves<->wolf isn't there), so verifying the low-risk rules here would
     * wrongly kill them. The Searcher applies it to riskyRootCandidates() only.
     */
    public function derivationVerifier(): ?callable
    {
        if (!$this->ready()) return null;
        $stmt = $this->db()->prepare("SELECT 1 FROM derivations WHERE word_a = ? AND word_b = ?");
        $cache = [];
        return function (string $word, string $root) use ($stmt, &$cache): bool {
            $key = $word . '|' . $root;
            if (!isset($cache[$key])) {
                $stmt->execute([$word, $root]);
                $cache[$key] = (bool)$stmt->fetchColumn();
            }
            return $cache[$key];
        };
    }

    /**
     * Derivationally related words, in the same group shape as
     * groupsFor(): [[word, related1, related2, ...], ...] — feed to the
     * Searcher's 'derivations' option. Covers what suffix rules can't reach
     * (decision->decide, quick->quickness). Words with no links produce no
     * group. Multi-word/numeric targets were already filtered at build time.
     */
    public function derivationsFor(array $words): array
    {
        if (!$this->ready()) return [];
        $stmt = $this->db()->prepare("SELECT word_b FROM derivations WHERE word_a = ?");
        $groups = [];
        foreach (array_unique($words) as $word) {
            $word = strtolower(trim((string)$word));
            if ($word === '') continue;
            $stmt->execute([$word]);
            $related = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            if (!empty($related)) $groups[] = array_merge([$word], $related);
        }
        return $groups;
    }

    /**
     * Plain lookup words from a query string: strips the query-syntax
     * operators (+ - " * ^N) and bare numbers, so only dictionary-shaped
     * words are sent to WordNet.
     */
    public static function queryWords(string $query): array
    {
        $q = strtolower($query);
        $q = preg_replace('/[+"*^]/', ' ', $q);
        $q = preg_replace('/-(?=[a-z])/', ' ', $q);
        $q = preg_replace('/\b\d+(\.\d+)?\b/', '', $q);
        return array_values(array_filter(preg_split('/\s+/', trim($q))));
    }
}
