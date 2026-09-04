<!-- MultiSearch — Simple English Wikipedia search demo -->
<!-- 2026-09-04 -->
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8" />
	<title>MultiSearch</title>
	<style>
	body {
		font-family: Verdana, sans-serif;
		max-width: 50rem;
		padding: 2rem;
		margin: auto;
		font-size: 1rem !important;
	}
	code, pre {
		font-family: monospace;
		background-color: #E6E6E6;
	}
	table {
		border: 0.1rem solid;
		width: 100%;
		table-layout: fixed;
		box-sizing: border-box;
	}
	td {
		padding: 0.5rem;
		vertical-align: top;
		overflow: hidden;
		word-break: break-word;
	}
	input[type=text] {
		width: 100%;
		box-sizing: border-box;
	}
	.query-row {
		display: flex;
		gap: 0.4rem;
	}
	.query-row input[type=text] {
		flex: 1;
		min-width: 0;
	}
	input[type=number] {
		width: 3.5rem;
	}
	input, select, textarea {
		font-family: monospace;
	}
	select {
		font-size: 0.95rem;
	}
	.field-label {
		font-family: monospace;
		font-size: 0.8rem;
		color: #555;
	}
	.no-results {
		color: #666;
		font-style: italic;
	}
	#algo-desc {
		color: #444;
		font-size: 0.85rem;
	}
	.meta-line {
		font-size: 0.85rem;
		color: #333;
		margin-top: 0.5rem;
	}
	.pagination {
		margin: 0.8rem 0;
		font-size: 0.9rem;
	}
	.pagination button {
		cursor: pointer;
		font-family: monospace;
		padding: 0.1rem 0.5rem;
	}
	.snippet {
		font-size: 0.85rem;
		color: #333;
		margin-top: 0.35rem;
		line-height: 1.55;
	}
	mark {
		background: #fff3cd;
		padding: 0 0.1rem;
		border-radius: 2px;
	}
	</style>
	<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
	<meta name="robots" content="noindex, nofollow, noarchive">
</head>
<body>

<h3>MultiSearch</h3>
<h4>Multi-field · Multi-algorithm · SQLite</h4>
<hr>

<?php

// Everything corpus-specific (DB paths, profile, default weights) comes from
// ONE shared config consumed by every entry point — this UI, the test suite,
// algo-compare — so they cannot drift apart. Before that file existed the
// profile and the 3.0/2.0/1.0 field weights were retyped in each of them,
// and tuning one silently left the others validating the old values.
// The index itself is built by scripts/build-index.php.
$CORPUS = require __DIR__ . '/config/corpus-wikipedia.php';
define('DB_PATH',      $CORPUS['db']);
define('OEWN_DB_PATH', $CORPUS['oewn_db']);

require __DIR__ . '/lib/MultiSearch.php';
require __DIR__ . '/lib/OewnSynonyms.php';

// ── Search logging ───────────────────────────────────────────────────────
// Logs every search to data/search_log.db. Schema auto-created on first use.
// Silently catches exceptions so logging never breaks a search.
//
// Beyond the requested settings and the two outcome numbers (total, elapsed),
// the log records what makes those columns interpretable:
//   algo_resolved  what auto actually picked ('algo' mostly says "auto" —
//                  the UI default)
//   two_phase/exhaustive  the two toggles that change what elapsed/total MEAN
//   oewn_senses    synonym breadth (the 'synonyms' bool hides 1-3, and it
//                  covers derivations too — they ride the same toggle)
//   per_page       affects snippet-fetch share of elapsed
//   elapsed_expand OEWN synonym/derivation lookup time, which runs OUTSIDE
//                  the search timer and was otherwise invisible
//   mem_kb         peak memory — OOM-adjacent behavior is a known failure mode
//   top_results    top-3 [doc_id,title,score] as JSON: turns the latency log
//                  into a relevance log ("what did this query return last
//                  week?" is answerable; regressions no longer need total
//                  to hit 0 to be visible)
//   index_built / engine  identity per row — rankings shift across rebuilds
//                  and engine versions; unattributable rows can't be compared
//   cached         this row was served from the result cache — its elapsed
//                  measures a cache fetch, not a search; logs.php excludes
//                  cached rows from latency averages
//   diag           compact diagnostics: typo swaps, expansion counts per
//                  class, candidate funnel
//
// There is NO schema migration, by design: the log is a disposable
// diagnostic artifact. After a schema change, delete data/search_log.db and
// it is recreated with the current schema on the next search. A stale-schema
// DB makes inserts throw, which the catch below swallows: logging silently
// stops until the file is deleted.
function logSearch(array $data): void {
	static $db = null;
	try {
		if ($db === null) {
			$db = new PDO('sqlite:' . __DIR__ . '/data/search_log.db');
			$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$db->exec("PRAGMA journal_mode = WAL");
			$db->exec("PRAGMA synchronous  = NORMAL");
			// Two concurrent searches both INSERT here; without a busy timeout
			// the second got SQLITE_BUSY instantly and the catch below silently
			// dropped its row. Wait up to 2s instead.
			$db->exec("PRAGMA busy_timeout = 2000");
			$db->exec("
				CREATE TABLE IF NOT EXISTS search_log (
					id             INTEGER PRIMARY KEY AUTOINCREMENT,
					ts             TEXT    NOT NULL,
					corpus         TEXT    NOT NULL,
					query          TEXT    NOT NULL,
					algo           TEXT    NOT NULL,
					confidence     INTEGER NOT NULL,
					fields         TEXT,
					stemming       INTEGER NOT NULL DEFAULT 0,
					stopwords      INTEGER NOT NULL DEFAULT 0,
					synonyms       INTEGER NOT NULL DEFAULT 0,
					total          INTEGER NOT NULL,
					page           INTEGER NOT NULL,
					elapsed        REAL    NOT NULL,
					ip             TEXT,
					algo_resolved  TEXT,
					two_phase      INTEGER NOT NULL DEFAULT 0,
					exhaustive     INTEGER NOT NULL DEFAULT 0,
					oewn_senses    INTEGER,
					per_page       INTEGER,
					elapsed_expand REAL,
					mem_kb         INTEGER,
					top_results    TEXT,
					index_built    TEXT,
					engine         TEXT,
					cached         INTEGER NOT NULL DEFAULT 0,
					diag           TEXT
				);
				CREATE INDEX IF NOT EXISTS idx_log_ts    ON search_log (ts);
				CREATE INDEX IF NOT EXISTS idx_log_query ON search_log (query);
			");
		}
		static $stmt = null;
		if ($stmt === null) {
			$stmt = $db->prepare("
				INSERT INTO search_log
					(ts, corpus, query, algo, confidence, fields, stemming, stopwords, synonyms,
					 total, page, elapsed, ip,
					 algo_resolved, two_phase, exhaustive, oewn_senses, per_page,
					 elapsed_expand, mem_kb, top_results, index_built, engine,
					 cached, diag)
				VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
			");
		}
		$stmt->execute([
			date('c'),
			$data['corpus']     ?? '',
			$data['query']      ?? '',
			$data['algo']       ?? '',
			(int)($data['confidence'] ?? 100),
			json_encode($data['fields'] ?? []),
			(int)!empty($data['stemming']),
			(int)!empty($data['stopwords']),
			(int)!empty($data['synonyms']),
			(int)($data['total']   ?? 0),
			(int)($data['page']    ?? 1),
			(float)($data['elapsed'] ?? 0),
			$_SERVER['REMOTE_ADDR'] ?? null,
			$data['algo_resolved'] ?? null,
			(int)!empty($data['two_phase']),
			(int)!empty($data['exhaustive']),
			$data['oewn_senses'] ?? null,          // null = OEWN off
			(int)($data['per_page'] ?? 20),
			(float)($data['elapsed_expand'] ?? 0),
			(int)(memory_get_peak_usage(true) / 1024),
			json_encode($data['top_results'] ?? [], JSON_UNESCAPED_UNICODE),
			$data['index_built'] ?? null,
			\MultiSearch\Searcher::VERSION,
			(int)!empty($data['cached']),
			$data['diag'] ?? null,
		]);
	} catch (Exception $e) {}
}

// ── Result cache ───────────────────────────────────────────────────────────
// Caches the top CACHE_DEPTH ranked hits per (query + every setting that
// changes results + index build + engine version), in data/result_cache.db
// (disposable, like all of data/). Two goals, one mechanism:
//   1. pagination: pages 2..N slice the cached superset instead of re-running
//      the whole pipeline (a page flip was a full search — parse, expand,
//      fetch, score — for a different 20-row window of the same ranking);
//   2. shared hits: a second user with the same query+settings gets the
//      cached ranking.
// Correctness comes from the KEY, not invalidation: index_built and
// engine VERSION are key components, so a rebuild or engine change simply
// never hits old entries (the index is immutable between rebuilds — results
// are deterministic per key). Stale entries age out: 7-day TTL + newest-500
// cap, pruned on write. Pages beyond CACHE_DEPTH bypass the cache entirely.
// App-level BY DESIGN: the engine stays a deterministic function of
// (index, query, options) — caching is deployment policy, like synonyms.
//
// RESULT_CACHE is the deployment switch. false = every search runs
// the full pipeline for exactly the requested page and the cache DB is
// never opened or written — for measuring true latency (logged elapsed
// times stop mixing ~8ms cache hits with ~900ms real searches), for A/B
// runs where a hit would mask a change, or where data/ must stay
// read-only. Existing data/result_cache.db is left alone; delete it by
// hand if you want it gone.
const RESULT_CACHE = true;
const CACHE_DEPTH  = 200;

function cacheDb(): ?PDO {
	static $db = null;
	if ($db instanceof PDO) return $db;
	if ($db === false) return null;
	try {
		$db = new PDO('sqlite:' . __DIR__ . '/data/result_cache.db');
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$db->exec("PRAGMA journal_mode = WAL");
		$db->exec("PRAGMA synchronous  = NORMAL");
		// Concurrent cache misses race to write; wait instead of silently
		// losing the write (a lost write = a redundant re-search later, not
		// an error — but free to avoid).
		$db->exec("PRAGMA busy_timeout = 2000");
		$db->exec("CREATE TABLE IF NOT EXISTS result_cache (
			key     TEXT    NOT NULL PRIMARY KEY,
			created INTEGER NOT NULL,
			payload TEXT    NOT NULL
		)");
		return $db;
	} catch (Exception $e) { $db = false; return null; }
}

function cacheGet(string $key): ?array {
	$db = cacheDb();
	if ($db === null) return null;
	try {
		$stmt = $db->prepare("SELECT payload FROM result_cache WHERE key = ? AND created > ?");
		$stmt->execute([$key, time() - 7 * 86400]);
		$payload = $stmt->fetchColumn();
		return $payload !== false ? json_decode($payload, true) : null;
	} catch (Exception $e) { return null; }
}

function cachePut(string $key, array $payload): void {
	$db = cacheDb();
	if ($db === null) return;
	try {
		$db->prepare("INSERT OR REPLACE INTO result_cache (key, created, payload) VALUES (?,?,?)")
		   ->execute([$key, time(), json_encode($payload, JSON_UNESCAPED_UNICODE)]);
		// Prune on write: TTL + newest-500 cap. Cheap at this scale (one
		// indexed DELETE + one subquery per WRITE, and writes are cache
		// misses only).
		$db->exec("DELETE FROM result_cache WHERE created < " . (time() - 7 * 86400));
		$db->exec("DELETE FROM result_cache WHERE key NOT IN
			(SELECT key FROM result_cache ORDER BY created DESC LIMIT 500)");
	} catch (Exception $e) {}
}

// Index identity for log rows and the cache key — meta.built_at (+ positional
// flag), read once per request, one PK lookup. Null only if the index can't
// be read: every builder writes the meta table.
function indexIdentity(): ?string {
	static $ident = false;
	if ($ident !== false) return $ident;
	$ident = null;
	try {
		$mdb  = new PDO('sqlite:' . DB_PATH, null, null,
			[PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
		$meta = $mdb->query("SELECT key, value FROM meta WHERE key IN ('built_at','positions')")
		            ->fetchAll(PDO::FETCH_KEY_PAIR);
		if (!empty($meta['built_at'])) {
			$ident = $meta['built_at'] . ((($meta['positions'] ?? '0') === '1') ? '+pos' : '');
		}
	} catch (Exception $e) {}
	return $ident;
}

// ── Check index ──────────────────────────────────────────────────────────
// Probes postings_body — the index has one postings table per field, and
// body is the one every Wikipedia article has.
function indexReady(): bool {
	if (!file_exists(DB_PATH) || filesize(DB_PATH) < 65536) return false;
	try {
		$db = new PDO('sqlite:' . DB_PATH, null, null,
			[PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
		// Existence probe instead of COUNT(*). COUNT(*) walks the entire
		// postings_body B-tree (~35M rows) and a LIMIT 1 on an aggregate is a
		// no-op — measured at 1.67s PER PAGE LOAD vs 0.14ms for this probe. A
		// leftover habit from the ~80-document corpus this project started on,
		// where counting everything was free.
		// Earlier: return (int)$db->query("SELECT COUNT(*) FROM postings_body LIMIT 1")->fetchColumn() > 0;
		return $db->query("SELECT 1 FROM postings_body LIMIT 1")->fetchColumn() !== false;
	} catch (Exception $e) { return false; }
}

if (!indexReady()) {
	echo '<p style="color:#b00;">wikipedia.db not found. Run <code>php scripts/build-index.php</code> to build the index.</p>';
	echo '</body></html>';
	exit;
}

// ── OEWN synonyms ──────────────────────────────────────────────────────────
// Synonym lookup lives in lib/OewnSynonyms.php. It began as three functions
// in this file (oewnReady / getOewnSynonyms / queryWords) that were also
// copy-pasted into scripts/test-suite.php, so every synonym fix had to be
// made twice; the library class is the single copy both now use.
$oewn = new \MultiSearch\OewnSynonyms(OEWN_DB_PATH);

// Wire the OEWN derivational verifier into the corpus profile — it unlocks
// the engine's risky stem rules (quickly->quick verified, summer->sum
// rejected). null when oewn.db is absent: the rules simply stay disabled.
$searchProfile = $CORPUS['profile'];
$stemVerifier  = $oewn->derivationVerifier();
if ($stemVerifier !== null) {
	$searchProfile['stem_verifier'] = $stemVerifier;
}

// ── Snippets ────────────────────────────────────────────────────────────
// The Searcher returns an escaped, highlighted snippet per hit
// ($r['snippet']), built from the documents table's opening text. An earlier
// version of this page fetched the opening and highlighted it itself, which
// was a second round-trip per hit for the same data.

// ── Defaults ──────────────────────────────────────────────────────────────
// The stopword list is loaded lazily, inside the search branch below, and
// only when stopword removal is on — it used to load on every page view,
// including plain GETs with no query.

$defaults = [
	'query'         => 'solar system planets',
	// 'auto' became the default when the labeled study measured it as the
	// best performer (macro-MRR .895 at the time, above every individual
	// algorithm); the default before that was 'bm25'.
	'algo'          => 'auto',
	'confidence'    => 85,
	// Weights come from the shared corpus config (they were literals retyped
	// here and in both benchmark scripts).
	'w_title'       => $CORPUS['weights']['title'],
	'w_opening'     => $CORPUS['weights']['opening'],
	'w_body'        => $CORPUS['weights']['body'],
	// Stemming + OEWN default ON — the verified expansion stack (stems,
	// derivations, synonyms) works out of the box; the checkbox note in the
	// search branch below explains why unticking still sticks. OEWN degrades
	// gracefully when oewn.db is absent.
	'stemming'      => '1',
	'remove_stopwords' => '',
	'use_oewn'      => '1',
	'oewn_senses'   => 1,
	'two_phase'     => '',
	'exhaustive'    => '',   // candidate_limit=0 — exact totals, slower
	'per_page'      => 20,
	'page'          => 1,
];

$post = $_POST + $defaults;

// Algorithm DESCRIPTIONS are app copy, not engine data. The engine exposes
// only the key list (Searcher::ALGOS, which also powers its fail-fast algo
// validation) and this page owns the English prose. An earlier design kept
// both in one public static on the Searcher, mixing a capability list with
// UI text.
$algoDescs = [
	'auto'     => 'Auto: automatically selects the best scoring algorithm for your query, based on a measured quality study. Wildcard queries and detected typos use Frequency (accumulates the canonical article\'s many matching variants). Question-style queries (how/what/who...) use BM25F. Everything else uses Coverage, letting the title-match bonus surface the canonical page. Best choice when you want good results without thinking about algorithms.',
	'bm25'     => 'BM25: industry-standard relevance formula. Combines word frequency (with diminishing returns — the 20th occurrence matters much less than the 2nd), word rarity, and document length normalization so longer articles don\'t dominate. Popular for full-text search.',
	'bm25+cov' => 'BM25+Coverage: BM25 relevance multiplied by a coverage bonus. A document matching all your search words gets a strong boost over partial matches, even if the partial match has higher raw BM25. Best for general-purpose search where you want the "obvious" result to rank #1.',
	'bm25f'    => 'BM25F: multi-field BM25. Instead of scoring title, opening, and body separately then averaging, it combines their term frequencies first, weighted by field importance, then scores once, and applies the same all-words coverage bonus as BM25+Coverage. This means a title match boosts relevance more naturally than averaging separate per-field scores.',
	'rrf'      => 'Reciprocal Rank Fusion: combines relevance depth (BM25) and query breadth (coverage) rankings using the reciprocal rank formula. Each algorithm contributes 1/(60+rank) to each document\'s final score. A document ranked #1 by both algorithms scores highest; one ranked high by only one still places well. Good for queries where neither relevance nor breadth alone gives the best ordering.',
	'dfr'      => 'DFR (Divergence From Randomness): scores documents by how much a word\'s frequency exceeds what random chance would predict. If "quantum" appears 5 times in an article but statistics say it should appear ~0.3 times, that strong divergence produces a high score. No tuning parameters (unlike BM25). Good for single-word or technical queries.',
	'cover'    => 'Coverage: document score = percentage of search query words found therein. A document containing 3 of 4 terms in the search query scores 75%, regardless of how many times they appear. Best when you want "match as many of these terms as possible" without caring about frequency or relevance depth.',
	'idf'      => 'Rarity (IDF): rare words count more than common ones. When searching for "the frankenstein monster", "frankenstein" contributes far more to documents\' scores than "the" because it appears in fewer documents. Like Frequency, but distinctive terms are amplified and generic terms are suppressed.',
	'freq'     => 'Frequency: document score = how often your search words appear in it, with diminishing returns (log-damped: 20 occurrences count about 3x as much as two, not 10x). No length normalization, so long articles still have an edge, and every matching spelling variant of a word adds up — which is why Auto picks it for wildcards and typos. Useful when repetition signals topical focus.',
];

// ── Run search ────────────────────────────────────────────────────────────
$result  = null;
$elapsed = null;

if (isset($_POST['query'])) {
	$query      = substr(trim($post['query']), 0, 150);
	$algo       = in_array($post['algo'], array_keys($algoDescs)) ? $post['algo'] : 'auto';
	$confidence = max(0, min(100, (int)$post['confidence']));
	$wTitle     = max(0.0, (float)$post['w_title']);
	$wOpening   = max(0.0, (float)$post['w_opening']);
	$wBody      = max(0.0, (float)$post['w_body']);
	// Checkboxes are read from $_POST DIRECTLY, not from $post.
	// $post = $_POST + $defaults, and an UNCHECKED checkbox sends no key at
	// all — so with a default of '1' the default would backfill the missing
	// key and the feature could never be turned off. The bug was latent while
	// every checkbox defaulted to ''; flipping the stemming/OEWN defaults on
	// made it real. On a submission, $_POST is the truth.
	// Earlier: $doStem = !empty($post['stemming']);  (and likewise for the rest)
	$doStem     = !empty($_POST['stemming']);
	$removeStop = !empty($_POST['remove_stopwords']);
	$useOewn    = !empty($_POST['use_oewn']) && $oewn->ready();
	$oewnSenses = in_array((int)$post['oewn_senses'], [1, 2, 3]) ? (int)$post['oewn_senses'] : 1;
	$twoPhase   = !empty($_POST['two_phase']);
	$exhaustive = !empty($_POST['exhaustive']);
	$perPage    = in_array((int)$post['per_page'], [5, 10, 20, 50]) ? (int)$post['per_page'] : 20;
	$page       = isset($_POST['do_search']) ? 1 : max(1, (int)$post['page']);

	// Loaded lazily — only when removal is actually on (see the Defaults note).
	$stopwords = [];
	if ($removeStop) {
		// The corpus config names the stopword list. The repo ships
		// config/stopwords.json (118 English words) by default — hand-curated
		// source, so it lives in config/, not in the disposable data/ dir.
		// To use your own list, point the 'stopwords' key of your corpus config
		// at it. The engine itself ships no list; a missing file means no
		// removal (the engine default).
		$stopwords = json_decode(@file_get_contents($CORPUS['stopwords'] ?? ''), true) ?? [];
	}
	$synonyms  = [];
	$derivations = [];
	$elapsedExpand = 0.0;   // OEWN lookup time — runs outside the search timer
	if ($useOewn) {
		$tExp = microtime(true);
		$qw = \MultiSearch\OewnSynonyms::queryWords($query);
		$synonyms = $oewn->groupsFor($qw, $oewnSenses);
		// Derivational expansion rides the same OEWN toggle.
		$derivations = $oewn->derivationsFor($qw);
		$elapsedExpand = round(microtime(true) - $tExp, 4);
	}

	$post['query']         = $query;
	$post['algo']          = $algo;
	$post['confidence']    = $confidence;
	$post['w_title']       = $wTitle;
	$post['w_opening']     = $wOpening;
	$post['w_body']        = $wBody;
	$post['stemming']      = $doStem ? '1' : '';
	$post['remove_stopwords'] = $removeStop ? '1' : '';
	$post['use_oewn']      = $useOewn ? '1' : '';
	$post['oewn_senses']   = $oewnSenses;
	$post['two_phase']     = $twoPhase ? '1' : '';
	$post['exhaustive']    = $exhaustive ? '1' : '';
	$post['per_page']      = $perPage;
	$post['page']          = $page;

	if ($query !== '') {
		// $searchProfile is the corpus profile from the shared config plus the
		// OEWN stem verifier (see above).
		$searchOpts = [
			'algo'       => $algo,
			'confidence' => $confidence,
			'fields'     => [
				'title'   => $wTitle,
				'opening' => $wOpening,
				'body'    => $wBody,
			],
			'stemming'  => $doStem,
			'stopwords' => $stopwords,
			'synonyms'  => $synonyms,
			'derivations' => $derivations,
			'two_phase' => $twoPhase,
		// Exhaustive = uncap the candidate prune (exact totals, every matching
		// doc scored). Combining it with Fast mode is contradictory but
		// harmless — two-phase still prunes to its phase-1 survivors first;
		// documented rather than blocked.
			// Diagnostics feed the expansion-transparency line under the
			// results and the compact diag column in the search log.
			'diagnostics' => true,
		] + ($exhaustive ? ['candidate_limit' => 0] : []);

		// Cache key = everything that changes results. page/per_page are
		// deliberately NOT in it — the cache stores the top-CACHE_DEPTH
		// superset once and every page slices it. Synonym/derivation GROUPS
		// aren't keyed either: they're derived deterministically from
		// (query, oewn on/off, senses), which are.
		$cacheKey = sha1(json_encode([
			mb_strtolower($query), $algo, $confidence,
			$wTitle, $wOpening, $wBody,
			$doStem, $removeStop, $useOewn ? $oewnSenses : null,
			$twoPhase, $exhaustive,
			indexIdentity(), \MultiSearch\Searcher::VERSION,
		]));

		$t0        = microtime(true);
		$fromCache = false;
		$full      = null;
		if (RESULT_CACHE && $page * $perPage <= CACHE_DEPTH) {
			$full = cacheGet($cacheKey);
			if ($full !== null) {
				$fromCache = true;
			} else {
				$searcher = new \MultiSearch\Searcher(DB_PATH, $searchProfile);
				$full = $searcher->search($query,
					$searchOpts + ['page' => 1, 'per_page' => CACHE_DEPTH]);
				cachePut($cacheKey, $full);
			}
			// Slice the superset for the requested window (keys preserved —
			// hits are keyed by doc id).
			$result = [
				'hits'     => array_slice($full['hits'], ($page - 1) * $perPage, $perPage, true),
				'total'    => $full['total'],
				'page'     => $page,
				'per_page' => $perPage,
				'pages'    => max(1, (int)ceil($full['total'] / $perPage)),
				'algo'     => $full['algo'],
			];
			if (isset($full['diag'])) $result['diag'] = $full['diag'];
		} else {
			// Cache off (RESULT_CACHE = false) or a deep page beyond the cached
			// superset (rare) — direct, uncached.
			$searcher = new \MultiSearch\Searcher(DB_PATH, $searchProfile);
			$result = $searcher->search($query,
				$searchOpts + ['page' => $page, 'per_page' => $perPage]);
		}
		$elapsed = round(microtime(true) - $t0, 4);

		// Top-3 results for the log — doc id, title, score.
		$logTop = [];
		foreach ($result['hits'] as $hDocId => $hHit) {
			$logTop[] = ['d' => $hDocId, 't' => $hHit['title'], 's' => $hHit['score']];
			if (count($logTop) >= 3) break;
		}

		logSearch([
			// Corpus name from the shared config (was the literal 'wikipedia')
			// — an adapter's corpus labels its own log rows automatically.
			'corpus'     => $CORPUS['name'] ?? '',
			'query'      => $query,
			'algo'       => $algo,
			'confidence' => $confidence,
			'fields'     => ['title' => $wTitle, 'opening' => $wOpening, 'body' => $wBody],
			'stemming'   => $doStem,
			'stopwords'  => $removeStop,
			'synonyms'   => $useOewn,
			'total'      => $result['total'],
			'page'       => $page,
			'elapsed'    => $elapsed,
			// enrichment columns (see the logSearch header)
			'algo_resolved'  => $result['algo'],
			'two_phase'      => $twoPhase,
			'exhaustive'     => $exhaustive,
			'oewn_senses'    => $useOewn ? $oewnSenses : null,
			'per_page'       => $perPage,
			'elapsed_expand' => $elapsedExpand,
			'top_results'    => $logTop,
			'index_built'    => indexIdentity(),
			'cached'         => $fromCache,
			'diag'           => (function () use ($result) {
				if (empty($result['diag'])) return null;
				$c = [];
				foreach ($result['diag']['expansions'] as $e) $c[$e['class']] = ($c[$e['class']] ?? 0) + 1;
				return json_encode([
					'swaps'      => $result['diag']['typo_swaps'],
					'expansions' => $c,
					'candidates' => $result['diag']['candidates'],
				], JSON_UNESCAPED_UNICODE);
			})(),
		]);
	}
}

// ── Form variables ────────────────────────────────────────────────────────
$qEsc     = htmlspecialchars($post['query'],          ENT_QUOTES);
$algSel   = htmlspecialchars($post['algo'],           ENT_QUOTES);
$conf     = (int)$post['confidence'];
$wT       = number_format((float)$post['w_title'],    1);
$wO       = number_format((float)$post['w_opening'],  1);
$wB       = number_format((float)$post['w_body'],     1);
$chkStem  = !empty($post['stemming'])      ? ' checked' : '';
$chkRemStop  = !empty($post['remove_stopwords']) ? ' checked' : '';
$chkOewn  = !empty($post['use_oewn'])     ? ' checked' : '';
$chk2P    = !empty($post['two_phase'])    ? ' checked' : '';
$chkExh   = !empty($post['exhaustive'])   ? ' checked' : '';
$sensesSel = (int)($post['oewn_senses'] ?? 1);
$ppSel    = (int)$post['per_page'];
$curPage  = (int)$post['page'];
$oewnAvail = $oewn->ready();

?>

<!-- <br><strong>Search:</strong> -->
<br>Corpus: <em>The Simple English Wikipedia</em> (~283K articles)
<br><br>

<form id="searchForm" action="#results" method="post">
<input type="hidden" name="page" id="pageInput" value="<?= $curPage ?>">
<table>
	<tr>
		<td style="width:28%;">Query:</td>
		<td>
			<div class="query-row">
				<input type="text" name="query" value="<?= $qEsc ?>"
					maxlength="150" autocomplete="off" list="searchpre" required>
				<!-- Second submit, inline with the box, so mouse users don't
				     have to scroll past the options table to search. Same
				     name as the bottom button: do_search = "new search, page 1". -->
				<input type="submit" name="do_search" value="Search!">
			</div>
			<!-- Themed (space/astronomy) example set — every entry
			     verified against the corpus; each demonstrates one feature:
			     multi-word, required, topic, typo, phrase+required,
			     exclusion-as-disambiguation, wildcard, boost, apostrophe,
			     hyphen, phrase exclusion, stemming, kitchen sink. -->
			<datalist id="searchpre">
				<option value="solar system planets">
				<option value="+asteroid extinction">
				<option value="supernova explosion star">
				<option value="jupitor">
				<option value="&quot;milky way&quot; +galaxy">
				<option value="+mercury -planet">
				<option value="astro*">
				<option value="nebula^2 star dust">
				<option value="halley&#39;s comet">
				<option value="gamma-ray burst">
				<option value="+&quot;solar system&quot; -&quot;black hole&quot;">
				<option value="orbiting planets rings">
				<option value="+astro*^1.5 &quot;solar system&quot; -&quot;black hole&quot; comet">
			</datalist>
		</td>
	</tr>
	<tr>
		<td>Algorithm:</td>
		<td>
			<select name="algo" id="algo-select">
				<?php
				// Full label map with an explicit order, Auto first (it's the
				// default and the measured best). The first version of this map
				// covered only the original five algorithms, so bm25f/rrf/dfr/auto
				// rendered as raw keys, and the list followed the engine's ALGOS
				// declaration order (auto last).
				// Earlier:
				// $algoLabels = [
				// 	'cover'   => 'Coverage',
				// 	'freq'    => 'Frequency',
				// 	'idf'     => 'Rarity',
				// 	'bm25'    => 'BM25',
				// 	'bm25+cov' => 'BM25+Coverage', // formerly 'ranked'
				// ];
				// foreach ($algoDescs as $k => $desc):
				$algoLabels = [
					'auto'     => 'Auto (recommended)',
					'bm25'     => 'BM25',
					'bm25+cov' => 'BM25+Coverage', // formerly 'ranked'
					'bm25f'    => 'BM25F',
					'dfr'      => 'DFR (InL2)',
					'rrf'      => 'Rank Fusion (RRF)',
					'cover'    => 'Coverage',
					'idf'      => 'Rarity (IDF)',
					'freq'     => 'Frequency',
				];
				foreach ($algoLabels as $k => $label):
					if (!isset($algoDescs[$k])) continue;
				?>
				<option value="<?= $k ?>"<?= $algSel === $k ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
				<?php endforeach; ?>
			</select>
			<br><small id="algo-desc"></small>
		</td>
	</tr>
	<tr>
		<td>Match confidence:</td>
		<td><input type="number" max="100" min="0" step="1"
			name="confidence" value="<?= $conf ?>" required>
		<br><small>0–100, &lt;100 = fuzzy. 100 = exact only; 85 allows near-matches.</small></td>
	</tr>
	<tr>
		<td>Field weights:</td>
		<td>
			<code>title</code>&nbsp;<input type="number" name="w_title"   min="0" step="0.5" value="<?= $wT ?>">
			&nbsp;&nbsp;
			<code>opening</code>&nbsp;<input type="number" name="w_opening" min="0" step="0.5" value="<?= $wO ?>">
			&nbsp;&nbsp;
			<code>body</code>&nbsp;<input type="number" name="w_body"    min="0" step="0.5" value="<?= $wB ?>">
			<br><small>0 = ignore field. Higher = more influence on ranking.</small>
		</td>
	</tr>
	<tr>
		<td>Results per page:</td>
		<td>
			<select name="per_page">
				<?php foreach ([5, 10, 20, 50] as $pp): ?>
				<option value="<?= $pp ?>"<?= $ppSel === $pp ? ' selected' : '' ?>><?= $pp ?></option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<!-- Rows grouped by concern — what MATCHES (stemming, synonyms,
	     stopwords: which terms participate) above how much is SEARCHED
	     (fast mode, exhaustive: the two ends of the speed/completeness
	     dial). Labels state the effect, with corpus-verified examples. -->
	<tr>
		<td>Stemming:</td>
		<td>
			<label><input type="checkbox" name="stemming" value="1"<?= $chkStem ?>>
				Expand search with root variants of query words (orbiting → orbit, moons → moon)</label>
		</td>
	</tr>
	<tr>
		<td>Synonyms:</td>
		<td>
			<?php if ($oewnAvail): ?>
				<label><input type="checkbox" name="use_oewn" value="1"<?= $chkOewn ?>>
					Expand search with synonyms &amp; derivations of words (planet → satellite, rotation → rotate).</label>
				<!-- <br> -->
				Senses:
				<select name="oewn_senses" style="width:auto;">
					<?php foreach ([1 => '1 (precise)', 2 => '2', 3 => '3 (broad)'] as $n => $label): ?>
					<option value="<?= $n ?>"<?= $sensesSel === $n ? ' selected' : '' ?>><?= $label ?></option>
					<?php endforeach; ?>
				</select>
			<?php else: ?>
				<span style="color:#999;">oewn.db not found — run <code>php scripts/build-oewn.php</code></span>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<td>Stopwords:</td>
		<td>
			<label><input type="checkbox" name="remove_stopwords" value="1"<?= $chkRemStop ?>>
				Skip common words (the, is, of…) while searching (never applied to +required words)</label>
		</td>
	</tr>
	<tr>
		<td>Fast mode:</td>
		<td>
			<label><input type="checkbox" name="two_phase" value="1"<?= $chk2P ?>>
				Two-phase retrieval (faster, may miss body-only matches)</label>
		</td>
	</tr>
	<tr>
		<td>Exhaustive:</td>
		<td>
			<label><input type="checkbox" name="exhaustive" value="1"<?= $chkExh ?>>
				Score every matching document in corpus instead of most promising (exact totals; slower on broad queries)</label>
		</td>
	</tr>
	<tr>
		<td></td>
		<td><input type="submit" name="do_search" value="Search!"></td>
	</tr>
</table>
</form>

<script>
const algoDescs = <?= json_encode($algoDescs) ?>;
const sel  = document.getElementById('algo-select');
const desc = document.getElementById('algo-desc');
function updateDesc() { desc.textContent = algoDescs[sel.value] ?? ''; }
sel.addEventListener('change', updateDesc);
updateDesc();

function goPage(n) {
	document.getElementById('pageInput').value = n;
	const form = document.getElementById('searchForm');
	// Two buttons share the name (top and bottom); disable both so a page
	// flip is never mistaken for a new search.
	form.querySelectorAll('[name=do_search]').forEach(b => { b.name = '_do_search_disabled'; });
	form.submit();
}
</script>

<br>

<?php if ($result !== null): ?>

<br>
<hr>
<br>
<a name="results"></a>
<b>Results:</b><br>

<?php if ($result['total'] === 0): ?>
	<br><span class="no-results">No results. Try a different query or lower the confidence.</span><br><br>
<?php else: ?>
	<ol start="<?= ($result['page'] - 1) * $result['per_page'] + 1 ?>">
	<?php foreach ($result['hits'] as $docId => $r):
		$score     = number_format($r['score'], 3);
		// Doc ids are opaque integers (Wikipedia page_id) — display title and
		// wiki URL come from the hit's 'title' (documents table), never from the id.
		$title     = $r['title'];
		$wikiUrl   = 'https://simple.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $title));

		$fieldParts = [];
		foreach ($r['matches'] as $field => $terms) {
			if (empty($terms)) continue;
			$termsHtml = implode(', ', array_map('htmlspecialchars', $terms));
			$fieldParts[] = '<code>' . htmlspecialchars($field) . '</code>: <em>' . $termsHtml . '</em>';
		}
		$matchLine = implode(' &nbsp;|&nbsp; ', $fieldParts) ?: '<em>—</em>';

		// The snippet comes pre-escaped and highlighted from the Searcher.
		$snippet = $r['snippet'];
	?>
		<li>
			<strong><a href="<?= htmlspecialchars($wikiUrl) ?>" target="_blank"><?= htmlspecialchars($title) ?></a></strong>
			(<?= $score ?>)<strong>:</strong>
			<!-- <?= '<span class="field-label">' . $matchLine . '</span>'?> -->
			<?php if ($snippet !== ''): ?>
			<div class="snippet"><?= $snippet ?></div>
			<?php endif; ?>
			<?php if (count($r['field_scores']) > 1): ?>
                <!-- <br> -->
                <small style="color: #888; margin-top: 0.5rem; display: block;">
                    field scores: 
                    <?php foreach ($r['field_scores'] as $f => $fs): ?>
                        <code><?= htmlspecialchars($f) ?></code>&nbsp;<?= number_format($fs, 3) ?>&ensp;
                    <?php endforeach; ?>
                </small>
                <?= '<small>findings: &nbsp; <span style="color: #888;">' . $matchLine . '</span></small>'?>
                <br><br>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
	</ol>

	<?php if ($result['pages'] > 1): ?>
	<div class="pagination">
		Page <?= $result['page'] ?> of <?= $result['pages'] ?>
		&nbsp;
		<?php if ($result['page'] > 1): ?>
			<button type="button" onclick="goPage(<?= $result['page'] - 1 ?>)">← Prev</button>
		<?php endif; ?>
		<?php if ($result['page'] < $result['pages']): ?>
			<button type="button" onclick="goPage(<?= $result['page'] + 1 ?>)">Next →</button>
		<?php endif; ?>
	</div>
	<?php endif; ?>
<?php endif; ?>

<div class="meta-line">
	Query: <em><?= htmlspecialchars($post['query']) ?></em><br>
	<?php
	// When Auto ran, show what it actually chose — the engine reports the
	// resolved algorithm in $result['algo'].
	$algoUsed = $result['algo'] ?? $post['algo'];
	$algoDisplay = ($post['algo'] === 'auto' && $algoUsed !== 'auto')
		? 'AUTO → ' . strtoupper($algoUsed)
		: strtoupper($post['algo']);
	?>
	Algorithm: <?= htmlspecialchars($algoDisplay) ?><?= !empty($fromCache) ? ' &nbsp;|&nbsp; <em>cached</em>' : '' ?><br>
	Fuzzy: <?= $conf < 100 ? 'on' : 'off' ?> &nbsp;|&nbsp; Confidence: <?= $conf ?><br>
	Fields: <code>title</code>×<?= $wT ?> &nbsp; <code>opening</code>×<?= $wO ?> &nbsp; <code>body</code>×<?= $wB ?><br>
	Stemming: <?= !empty($post['stemming']) ? 'on' : 'off' ?>
	&nbsp;|&nbsp; Stopwords: <?= !empty($post['remove_stopwords']) ? 'on' : 'off' ?>
	&nbsp;|&nbsp; Synonyms: <?= !empty($post['use_oewn']) ? 'on (senses=' . (int)($post['oewn_senses'] ?? 1) . ')' : 'off' ?><br>
	<?php
	// Expansion transparency — the diag channel makes "what did my
	// query actually search for?" answerable in the UI. Same trust story as
	// exact phrase operators: no silent rewriting.
	$diag = $result['diag'] ?? null;
	if ($diag !== null):
		$parts = [];
		foreach ($diag['typo_swaps'] as $sw) {
			$parts[] = 'corrected <em>' . htmlspecialchars($sw['typed']) . '</em> → <em>'
				. htmlspecialchars($sw['promoted']) . '</em>';
		}
		$byClass = [];
		foreach ($diag['expansions'] as $e) $byClass[$e['class']][] = $e['term'];
		foreach ($byClass as $cls => $terms) {
			$shown = array_slice(array_unique($terms), 0, 6);
			$more  = count(array_unique($terms)) - count($shown);
			$parts[] = htmlspecialchars($cls) . ': <em>' . htmlspecialchars(implode(', ', $shown))
				. ($more > 0 ? ", +$more" : '') . '</em>';
		}
		foreach ($diag['wildcards'] as $prefix => $w) {
			$parts[] = htmlspecialchars($prefix) . ' → ' . (int)$w['count'] . ' terms';
		}
		if (!empty($parts)): ?>
	Also searched — <?= implode(' &nbsp;|&nbsp; ', $parts) ?><br>
	<?php endif; endif; ?>
	<?php
	// Honest totals. Without Exhaustive, the engine scores at most
	// candidate_limit docs (engine default 5000 — see the corpus-profile key
	// 'candidate_limit' in lib/MultiSearch.php), so a total AT the cap is a
	// sample, not a count. Earlier versions printed the raw number as exact.
	$isCapped = !$exhaustive && $result['total'] >= 5000;
	?>
	Results: <?= $isCapped
		? '5,000+ <small>(capped — enable exhaustive mode for exact count)</small>'
		: number_format($result['total']) . ' total' ?>
	&nbsp;|&nbsp; Page: <?= $result['page'] ?>/<?= $result['pages'] ?>
	&nbsp;|&nbsp; Time: <?= $elapsed ?>s
</div>

<?php endif; ?>

<br><br>
<hr>
<br>
<b>Query syntax:</b>
<?php
// The phrase bullet is CONDITIONAL on the index — quotes mean adjacency on a
// positional index (the default build) and word-level all-words-required
// semantics on a --no-positions build. indexIdentity() is already cached per
// request for the search log, so this costs nothing extra.
$ixPositional = str_contains((string)indexIdentity(), '+pos');
?>
<ul>
	<li><code>sun moon comet</code> — Optional words: any may match; documents matching
		more of them rank higher. Typo-tolerant when Confidence &lt; 100
		(<code>jupitor</code> finds Jupiter).</li><br>
	<li><code>+comet</code> — Required: only documents containing it are returned.
		Required words are matched exactly, never typo-corrected.</li><br>
	<li><code>-pluto</code> — Excluded: no document containing it is returned.</li><br>
	<?php if ($ixPositional): ?>
	<li><code>"solar system"</code> — Exact phrase: the words must appear next to each
		other, in this order, in one field (<code>"system solar"</code> is a different
		search). <code>+"..."</code> is the same; <code>-"black hole"</code> excludes
		documents containing the phrase.</li><br>
	<?php else: ?>
	<li><code>"solar system"</code> — All words required (this index was built without
		positions: word order and adjacency are not checked; <code>-"black hole"</code>
		excludes documents with all the phrase's words in one field).</li><br>
	<?php endif; ?>
	<li><code>astro*</code> — Prefix wildcard: matches <em>astronomy, astronaut, …</em>
		(the 100 rarest completions). <code>-galax*</code> excludes <b>every</b> matching
		document — exclusions have no cap.</li><br>
	<li><code>nebula^2</code> — Weight a word in scoring (any factor, e.g. <code>^1.5</code>).</li><br>
	<li>Everything combines: <code>+astro*^1.5 "solar system" -"black hole" comet</code></li><br>
	<li>Punctuation is normalized: <code>halley's</code> finds <em>halleys</em>,
		<code>gamma-ray</code> searches <em>gamma ray</em> — and inside quotes the words
		stay consecutive.</li><br>
	<li>Precedence: excluded > required > optional. Expansion options (stemming,
		synonyms) add word forms — added forms never outrank the words you typed.</li>
</ul>

<br>
<hr>
<small>
	<a href="https://github.com/aaviator42/MultiSearch">MultiSearch</a> v<?= htmlspecialchars(\MultiSearch\Searcher::VERSION) ?> (AGPLv3) &nbsp;|&nbsp;
	Corpus: <a href="https://simple.wikipedia.org/">Simple English Wikipedia</a> (CC BY-SA 3.0) &nbsp;|&nbsp;
	<a href="admin.php">Admin / Stats</a>
</small>

</body>
</html>
