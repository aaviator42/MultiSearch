<?php
/*
scripts/test-suite.php — Comprehensive search engine test suite.

Tests all algorithms, all features, ranking quality, fuzzy discounting, and
performance across the full matrix. Saves results to data/test-results/ as
timestamped JSON files so you can compare across iterations.

Run:  php scripts/test-suite.php
      php scripts/test-suite.php --save         (save results to JSON)
      php scripts/test-suite.php --compare      (compare last 2 saved runs)
      php scripts/test-suite.php --compare N    (compare last N runs)
      php scripts/test-suite.php --fast         (skip perf + comparison groups)
      php scripts/test-suite.php --labeled-only (just the labeled ranking study)
      php scripts/test-suite.php --db=data/other.db --label=x --save
                                                (run against another index
                                                 build — A/B a rebuild vs live)
      php scripts/test-suite.php --rk='{"k1":1.2}' --label=k1-1.2 --save
                                                (whole suite under knob overrides;
                                                 compare runs with compare-configs.php)

── History ────────────────────────────────────────────────────────────────────
  The suite was created to track ranking changes while iterating on fuzzy
  discounting. The "value vs valve" problem (a fuzzy match outranking the
  typed term under BM25) was the motivation: a distance-based boost
  (pow(confidence/100, distance)) fixed it for coverage but BM25 still had
  issues, so the suite captures the full picture across all algorithms to
  diagnose. It has since grown into the regression gate for every engine
  change: declarative assertions, performance budgets, positional and
  diacritic-folding semantics, and the labeled ranking study.
*/

ini_set('memory_limit', '512M');

require __DIR__ . '/../lib/MultiSearch.php';
require __DIR__ . '/../lib/OewnSynonyms.php';

// Corpus facts (paths, profile, weights) come from the SAME config file
// production uses — the whole point of this suite is validating what index.php
// actually serves, and a retyped profile here (which is how it started) could
// silently drift from it.
$CORPUS  = require __DIR__ . '/../config/corpus-wikipedia.php';
$dataDir = dirname($CORPUS['db']);
// --db=path overrides the corpus index (A/B across builds, e.g. the
// diacritic-folded rebuild vs the live one). Results still land next to
// the CORPUS db so --compare sees both runs.
$dbOverride = null;
foreach ($argv ?? [] as $arg) {
	if (str_starts_with($arg, '--db=')) $dbOverride = substr($arg, 5);
}
define('DB_PATH', $dbOverride ?? $CORPUS['db']);
define('OEWN_DB_PATH', $CORPUS['oewn_db']);
define('RESULTS_DIR', $dataDir . '/test-results');

if (!file_exists(DB_PATH)) {
	fwrite(STDERR, "Error: wikipedia.db not found. Run scripts/build-index.php first.\n");
	exit(1);
}

// Does this index carry token positions? Phrase semantics differ
// (adjacency vs non-positional cross-field AND), so positionality-sensitive tests
// declare BOTH expectations via 'when_positional' / 'when_nonpositional' overrides
// and the runner merges the applicable one (see the runner loop). The flag
// is also recorded in saved results so --compare across a rebuild is
// interpretable.
$INDEX_POSITIONAL = false;
try {
	$chk = new PDO('sqlite:' . DB_PATH);
	$cols = $chk->query("PRAGMA table_info(postings_body)")->fetchAll(PDO::FETCH_COLUMN, 1);
	$INDEX_POSITIONAL = in_array('pos', $cols, true);
	$chk = null;
} catch (Exception $e) { /* non-positional assumption stands */ }

// Was this index built with diacritics folded (meta.tokenizer_name)?
// Accent-sensitive tests declare 'when_folded' / 'when_unfolded' overrides,
// merged like the positional ones. Recorded in saved results too.
$INDEX_FOLDED = false;
try {
	$chk = new PDO('sqlite:' . DB_PATH);
	$INDEX_FOLDED = $chk->query("SELECT value FROM meta WHERE key = 'tokenizer_name'")->fetchColumn()
		=== \MultiSearch\Searcher::TOKENIZER_FOLDED;
	$chk = null;
} catch (Exception $e) { /* unfolded assumption stands */ }

$doSave    = in_array('--save', $argv ?? []);
$doCompare = in_array('--compare', $argv ?? []);
$doFast    = in_array('--fast', $argv ?? []);
$doLabeledOnly = in_array('--labeled-only', $argv ?? []);
$compareN  = 2;
foreach ($argv ?? [] as $i => $arg) {
	if ($arg === '--compare' && isset($argv[$i + 1]) && is_numeric($argv[$i + 1])) {
		$compareN = (int)$argv[$i + 1];
	}
}

// ── Ranking-knob overrides ────────────────────────────────────────────────
// --rk='{"knob": value, ...}' applies overrides to EVERY search (declarative
// tests + labeled study), so the baseline assertions double as a regression
// gate for a proposed tuning: a knob that breaks a clean query FAILS loudly,
// and the labeled study's MRR says what it bought. --label=<name> stamps the
// run (saved JSON + filename) so compare-configs.php can group runs by it;
// it defaults to 'baseline' with no overrides. Unknown knob keys throw in
// the engine. (The typo-ranking sweep that tuned the swap guards used a
// table of named presets here; --rk replaced it once the winners became
// the engine defaults.)
$CONFIG_RK   = [];
$CONFIG_NAME = 'baseline';
foreach ($argv ?? [] as $arg) {
	if (str_starts_with($arg, '--rk=')) {
		$CONFIG_RK = json_decode(substr($arg, 5), true);
		if (!is_array($CONFIG_RK)) { fwrite(STDERR, "--rk must be a JSON object of knob => value\n"); exit(1); }
		if ($CONFIG_NAME === 'baseline') $CONFIG_NAME = 'rk';
	} elseif (str_starts_with($arg, '--label=')) {
		$CONFIG_NAME = preg_replace('/[^A-Za-z0-9_.-]+/', '-', substr($arg, 8)) ?: 'baseline';
	}
}

// ═════════════════════════════════════════════════════════════════════════════
// OEWN synonyms
// ═════════════════════════════════════════════════════════════════════════════
// This file used to carry ~55 lines copy-pasted verbatim from index.php
// (oewnReady / getOewnSynonyms / queryWords — the header here even confessed
// "mirrors index.php"), so every synonym fix had to be made twice. Both now
// use lib/OewnSynonyms.php.

$oewn = new \MultiSearch\OewnSynonyms(OEWN_DB_PATH);

// ═════════════════════════════════════════════════════════════════════════════
// Test definitions
// ═════════════════════════════════════════════════════════════════════════════

// Field weights from the shared config, never retyped here.
$defaultFields = $CORPUS['weights'];
// (Saved results from before the rename carry the key 'ranked' — that
// algorithm is today's 'bm25+cov'.)
// Every scoring algorithm the engine exposes (Searcher::ALGOS); 'auto' last
// so its resolved pick is easy to compare against the explicit rows above it.
$algos = ['bm25', 'cover', 'idf', 'freq', 'bm25+cov', 'bm25f', 'rrf', 'dfr', 'auto'];

$tests = [];

// ── Section 1: Ranking quality — across all algos ────────────────────────
// These test whether the right doc ranks #1 or in the top N.
// Each test runs on every algorithm in $algos so we can compare. (The roster
// started at five; bm25f, rrf, dfr and auto joined in later iterations.)

$rankingTests = [
	// Basic relevance: exact title match should rank high
	['id' => 'rank-einstein',
	 'query' => 'albert einstein', 'confidence' => 85,
	 'expect_top' => 'Albert Einstein',
	 'note' => 'Exact title match should be #1 across all algos'],

	// freq algo is excluded: it scores raw term count, so "Bob" (body mentions
	// bob 64+ times) will always beat "Bob Einstein" (body=10). This is by design.
	['id' => 'rank-bob-einstein',
	 'query' => 'bob einstein', 'confidence' => 85,
	 'expect_top' => 'Bob Einstein',
	 'note' => 'Two-word title match. Freq excluded: favors long docs with many mentions.'],

	['id' => 'rank-python-myth',
	 'query' => 'python mythology', 'confidence' => 100,
	 'expect_top' => 'Python (mythology)',
	 'note' => 'Title match should beat body-only matches'],

	// Fuzzy discounting: original term should rank above fuzzy match
	// This was the "smart vs shart" problem — fuzzy match outranked original.
	// v1 fix: distance-based boost pow(confidence/100, distance) — worked for
	// coverage but the ~15% discount was too weak for BM25; superseded by
	// decay^distance (default 0.5 — see expandFlat / 'fuzzy_decay' knob).
	// "Smart" and "SMart" are both valid exact-match results with identical
	// scores (same token "smart" in a single-word title). Accept either.
	// The key assertion: "Shart" (fuzzy match) should NOT be #1.
	['id' => 'fuzzy-smart-vs-shart',
	 'query' => 'smart', 'confidence' => 85,
	 'expect_in_top' => ['Smart', 'SMart'], 'top_n' => 1,
	 'note' => 'Original "smart" should outrank fuzzy match "shart"'],

	['id' => 'fuzzy-einstein-typo',
	 'query' => 'einstien', 'confidence' => 85,
	 'expect_in_top' => ['Albert Einstein', 'Einstein (unit)', 'Bob Einstein'], 'top_n' => 5,
	 'note' => 'Common typo should fuzzy-match to Einstein articles'],

	['id' => 'rank-solar-system',
	 'query' => 'solar system', 'confidence' => 100,
	 'expect_top' => 'Solar System',
	 'note' => 'Exact two-word title match'],

	['id' => 'rank-quantum',
	 'query' => 'quantum mechanics', 'confidence' => 100,
	 'expect_top' => 'Quantum mechanics',
	 'note' => 'Exact title match for physics topic'],

	['id' => 'rank-violet',
	 'query' => 'violet', 'confidence' => 100,
	 'expect_in_top' => ['Violet', 'Violet (color)'], 'top_n' => 3,
	 'note' => 'Exact single-word title match in top 3'],

	['id' => 'rank-monty-python',
	 'query' => 'monty python', 'confidence' => 100,
	 'expect_in_top' => ['Monty Python', 'Monty Python and the Holy Grail'], 'top_n' => 3,
	 'note' => 'Multi-word match in top 3'],

	['id' => 'rank-water',
	 'query' => 'water', 'confidence' => 100,
	 'expect_top' => 'Water',
	 'note' => 'Very common word — title match should still be #1'],
];

// Expand ranking tests across all algorithms.
// freq excluded from expect_top assertions — it scores raw term count, so
// long docs with many mentions will always outrank short title matches.
// That's by design; we keep freq tests for comparison data without assertions.
foreach ($rankingTests as $rt) {
	foreach ($algos as $algo) {
		$test = $rt;
		$test['id']    = $rt['id'] . '-' . $algo;
		$test['algo']  = $algo;
		$test['group'] = 'ranking';
		// freq: drop ranking assertions, keep test for data capture
		if ($algo === 'freq') {
			unset($test['expect_top']);
			unset($test['expect_in_top']);
		}
		$tests[] = $test;
	}
}

// ── Section 2: Fuzzy matching — correctness ──────────────────────────────
// Test that fuzzy expansion finds the right terms and doesn't find wrong ones.

$fuzzyTests = [
	['id' => 'fuzzy-dinasaur',
	 'query' => 'dinasaur', 'confidence' => 80,
	 'expect_min' => 1,
	 'note' => 'Common misspelling should find dinosaur articles'],

	['id' => 'fuzzy-elefant',
	 'query' => 'elefant', 'confidence' => 80,
	 'expect_min' => 1,
	 'note' => 'Phonetic misspelling should find elephant articles'],

	['id' => 'fuzzy-exact-at-100',
	 'query' => 'dinosaur', 'confidence' => 100,
	 'expect_min' => 5,
	 'note' => 'Confidence 100 = exact only, should still find results'],

	['id' => 'fuzzy-nonsense',
	 'query' => 'xyzzyplughfoo', 'confidence' => 80,
	 'expect_max' => 0,
	 'note' => 'Complete nonsense should find nothing even with fuzzy'],

	['id' => 'fuzzy-multiword',
	 'query' => 'einsten relitivity', 'confidence' => 80,
	 'expect_min' => 1,
	 'note' => 'Multiple typos in multi-word query'],

	// Fuzzy boost ordering: verify distance-based discount works.
	// "smart" (dist 0) boosts 1.0 while "shart"/"start" (dist 1) get 0.5
	// (fuzzy_decay^distance). An earlier formula, pow(confidence/100, distance),
	// gave 0.85 here; the flat decay knob replaced it.
	['id' => 'fuzzy-boost-ordering',
	 'query' => 'smart', 'confidence' => 85,
	 'expect_top' => 'Smart',
	 'note' => 'Exact term should outrank fuzzy matches due to distance-based boost'],
];

foreach ($fuzzyTests as $ft) {
	$ft['algo']  = $ft['algo'] ?? 'bm25';
	$ft['group'] = 'fuzzy';
	$tests[] = $ft;
}

// ── Section 2b: Auto-routing invariants ──────────────────────────────────
// expect_algo asserts what 'auto' resolves to. Only expectations that hold
// under EVERY knob config belong here; config-sensitive routing (the whole
// point of the sweep) is MEASURED in the labeled study instead.

$routingTests = [
	['id' => 'route-clean-cover',
	 'query' => 'albert einstein', 'confidence' => 85, 'algo' => 'auto',
	 'expect_algo' => 'cover', 'expect_top' => 'Albert Einstein',
	 'note' => 'Clean multi-word name: no typo evidence under any knob config'],

	// einstien df=2: under every config the swap still fires (df under any
	// ceiling; single word, so no context vouchers) -> typo evidence -> freq.
	['id' => 'route-typo-freq',
	 'query' => 'einstien', 'confidence' => 85, 'algo' => 'auto',
	 'expect_algo' => 'freq',
	 'note' => 'Single-word corpus typo: swaps under every config -> freq'],
];
foreach ($routingTests as $rt) {
	$rt['group'] = 'routing';
	$tests[] = $rt;
}

// ── Section 3: Query syntax features ─────────────────────────────────────

$syntaxTests = [
	['id' => 'syntax-required',
	 'query' => '+dinosaur extinction', 'confidence' => 100,
	 'expect_min' => 1,
	 'note' => 'Required term with optional term'],

	['id' => 'syntax-excluded',
	 'query' => '+football -american', 'confidence' => 100,
	 'expect_min' => 1,
	 'note' => 'Required + excluded terms'],

	['id' => 'syntax-phrase',
	 'query' => '"united states"', 'confidence' => 100,
	 'expect_min' => 100,
	 'note' => 'Quoted phrase makes all words required'],

	['id' => 'syntax-boost',
	 'query' => 'einstein^3 relativity', 'confidence' => 100,
	 'expect_min' => 5,
	 'note' => 'Numeric boost on term'],

	['id' => 'syntax-wildcard',
	 'query' => 'comput*', 'confidence' => 100,
	 'expect_min' => 50,
	 'note' => 'Wildcard prefix expansion'],

	['id' => 'syntax-req-wildcard',
	 'query' => '+astro* planet', 'confidence' => 100,
	 'expect_min' => 1,
	 'note' => 'Required wildcard'],

	['id' => 'syntax-excluded-only',
	 'query' => '-water', 'confidence' => 100,
	 'expect_max' => 0,
	 'note' => 'Excluded-only query should return nothing'],

	['id' => 'syntax-all-required',
	 'query' => '+quantum +tunnel', 'confidence' => 100,
	 'expect_min' => 1,
	 'note' => 'Both terms required narrows results'],
];

foreach ($syntaxTests as $st) {
	$st['algo']  = 'bm25';
	$st['group'] = 'syntax';
	$tests[] = $st;
}

// ── Section 4: Feature matrix ────────────────────────────────────────────
// Test each feature in isolation and combined.

$featureTests = [
	['id' => 'feat-stemming',
	 'query' => 'running', 'confidence' => 100, 'stemming' => true,
	 'expect_min' => 1,
	 'note' => 'Stemming: running → run variants'],

	['id' => 'feat-stopwords',
	 'query' => 'the water', 'confidence' => 100,
	 'stopwords' => true,
	 'expect_min' => 1,
	 'note' => 'Stopwords: "the" filtered, "water" searched'],

	['id' => 'feat-stopwords-all',
	 'query' => 'the a an', 'confidence' => 100,
	 'stopwords' => true,
	 'expect_min' => 0,
	 'note' => 'All stopwords filtered = no search terms'],

	['id' => 'feat-synonyms',
	 'query' => 'monster', 'confidence' => 100,
	 'synonyms' => true,
	 'expect_min' => 1,
	 'note' => 'Synonym expansion (requires oewn.db)'],

	['id' => 'feat-stem-fuzzy',
	 'query' => 'runing', 'confidence' => 85, 'stemming' => true,
	 'expect_min' => 1,
	 'note' => 'Stemming + fuzzy combined: typo "runing" → run'],

	['id' => 'feat-all-combined',
	 'query' => 'smart device', 'confidence' => 85,
	 'stemming' => true, 'stopwords' => true,
	 'expect_min' => 1,
	 'note' => 'All features enabled together'],
];

foreach ($featureTests as $ft) {
	$ft['algo']  = 'bm25';
	$ft['group'] = 'features';
	$tests[] = $ft;
}

// ── Section 5: Performance / stress tests ────────────────────────────────
// Thresholds were tightened after the storage-layer experiments brought a
// ~10x speedup (the per-field WITHOUT ROWID schema chief among them).
// Earlier thresholds: 5-10s (acceptable before optimization).
// Current thresholds: 0.5-2s (real-world web search latency budgets).
// A search engine that takes >1s feels broken to humans.
// Four caps were later re-calibrated against a clean run on the dev machine
// (WSL + php.exe on NTFS) — exact-simple measured .26s vs cap .3, broad-topic
// .90 vs 1.0, long-query 2.82 vs 3.0, question-what .37 vs .5 sat inside
// normal disk jitter and flaked on otherwise-green runs (exact-simple failed
// even on the unmodified engine). Raised to .5/1.5/4.0/.75; the other 13 caps
// had 45-97% headroom and stay as they were. The caps exist to catch
// order-of-magnitude regressions, not 10% variance — and runs must be alone on
// the disk to be meaningful.

$perfTests = [
	['id' => 'perf-common-word',
	 'query' => 'water', 'confidence' => 85,
	 'max_time' => 0.5,
	 'note' => 'Very common word with fuzzy — fast with two-phase retrieval'],

	['id' => 'perf-long-query',
	 'query' => 'history of the roman empire and its influence on modern western civilization',
	 'confidence' => 85, 'max_time' => 4.0,
	 'note' => 'Long query with many terms + fuzzy — worst case scenario'],

	['id' => 'perf-single-char',
	 'query' => 'a', 'confidence' => 85,
	 'max_time' => 0.5,
	 'note' => 'Single character query'],

	['id' => 'perf-wildcard-broad',
	 'query' => 'th*', 'confidence' => 100,
	 'max_time' => 1.0,
	 'note' => 'Broad wildcard — many matches'],

	['id' => 'perf-fuzzy-multiword',
	 'query' => 'einstein albert relativity', 'confidence' => 85,
	 'max_time' => 1.0,
	 'note' => '3-word fuzzy query performance'],

	['id' => 'perf-exact-simple',
	 'query' => 'solar system', 'confidence' => 100,
	 'max_time' => 0.5,
	 'note' => 'Simple exact 2-word — should be near-instant'],

	['id' => 'perf-fuzzy-typo',
	 'query' => 'einstien', 'confidence' => 85,
	 'max_time' => 0.3,
	 'note' => 'Single-word typo correction — lightweight'],
];

foreach ($perfTests as $pt) {
	$pt['algo']  = 'bm25';
	$pt['group'] = 'performance';
	$tests[] = $pt;
}

// ── Section 5b: Real-world query patterns ────────────────────────────────
// Humans search differently than test queries. These cover the most common
// patterns from real search logs: single words, questions, partial names,
// topic exploration, and disambiguation.

$realWorldTests = [
	// Single word — most common search pattern
	['id' => 'rw-single-word',
	 'query' => 'photosynthesis', 'confidence' => 100,
	 'expect_top' => 'Photosynthesis',
	 'max_time' => 0.3,
	 'note' => 'Single exact word — most common search pattern'],

	// Question-style queries — users type like they talk
	['id' => 'rw-question-what',
	 'query' => 'what is dark matter', 'confidence' => 100,
	 'expect_in_top' => ['Dark matter'], 'top_n' => 3,
	 'max_time' => 0.75,
	 'note' => 'Question-style query — stopwords should help'],

	['id' => 'rw-question-who',
	 'query' => 'who discovered penicillin', 'confidence' => 100,
	 'expect_in_top' => ['Penicillin', 'Alexander Fleming'], 'top_n' => 5,
	 'max_time' => 1.0,
	 'note' => 'Question about a discovery'],

	// Partial name — users often search just last name
	['id' => 'rw-partial-name',
	 'query' => 'shakespeare', 'confidence' => 100,
	 'expect_in_top' => ['William Shakespeare'], 'top_n' => 3,
	 'max_time' => 0.3,
	 'note' => 'Last name only — should find the person'],

	// Topic + qualifier — narrowing intent
	['id' => 'rw-topic-qualifier',
	 'query' => 'mars planet', 'confidence' => 100,
	 'expect_in_top' => ['Mars'], 'top_n' => 3,
	 'max_time' => 0.3,
	 'note' => 'Topic + qualifier to disambiguate'],

	// Geographic query
	['id' => 'rw-geography',
	 'query' => 'mount everest', 'confidence' => 100,
	 'expect_in_top' => ['Mount Everest'], 'top_n' => 1,
	 'max_time' => 0.3,
	 'note' => 'Geographic landmark — exact title match'],

	// Multi-word fuzzy with typos — realistic user input
	['id' => 'rw-multi-typo',
	 'query' => 'artifical inteligence', 'confidence' => 85,
	 'expect_in_top' => ['Artificial intelligence'], 'top_n' => 3,
	 'max_time' => 1.0,
	 'note' => 'Two typos in a 2-word query — realistic user error'],

	// Broad topic exploration
	// "World War II" won't match "world war two" at conf 100 — "two" != "II".
	// Fuzzy at 85 can bridge this gap. At conf 100 we accept any war-related result.
	['id' => 'rw-broad-topic',
	 'query' => 'world war two', 'confidence' => 100,
	 'expect_in_top' => ['World War II', 'World war', 'World War I', 'World War III'], 'top_n' => 3,
	 'max_time' => 1.5,
	 'note' => 'Broad topic — "two" vs "II" mismatch expected at exact conf'],

	// Short abbreviation-like query
	['id' => 'rw-abbreviation',
	 'query' => 'DNA', 'confidence' => 100,
	 'expect_in_top' => ['DNA'], 'top_n' => 3,
	 'max_time' => 0.3,
	 'note' => 'Uppercase abbreviation search'],

	// Disambiguation — ambiguous term
	['id' => 'rw-disambig',
	 'query' => 'mercury', 'confidence' => 100,
	 'expect_min' => 5,
	 'max_time' => 0.3,
	 'note' => 'Ambiguous word — should find planet, element, or god'],
];

foreach ($realWorldTests as $rw) {
	$rw['algo']  = $rw['algo'] ?? 'bm25';
	$rw['group'] = 'real-world';
	$tests[] = $rw;
}

// ── Section 5c: Expansion & syntax-combination regressions ───────────────
// Added after a fresh-eyes audit + adversarial stress testing found bugs
// this suite never covered: the stemmed +required AND-bug (+wolves with
// stemming returned 5 docs instead of ~1800), the excluded-wildcard leak
// (rarest-first cap dropped "american" from -americ*), and the tokenizer
// drift ("don't" -> 0 hits while the index held "dont"). These lock the
// fixes in so they can't silently regress.

$expansionTests = [
	['id' => 'exp-stem-req-or',
	 'query' => '+wolves', 'confidence' => 100, 'stemming' => true,
	 'expect_top' => 'Wolf', 'expect_min' => 1500,
	 'note' => 'Stemmed required term is an OR-group (wolves OR wolf), not AND'],

	['id' => 'exp-stem-concept-title',
	 'query' => 'wolves', 'confidence' => 100, 'stemming' => true,
	 'expect_top' => 'Wolf',
	 'note' => 'Concept title bonus: a title matching any FORM fills the slot'],

	['id' => 'exp-stem-irregular',
	 'query' => 'children', 'confidence' => 100, 'stemming' => true,
	 'expect_min' => 4000,
	 'note' => 'Irregular plural map: children also finds child docs'],

	['id' => 'exp-stem-doubled',
	 'query' => 'running', 'confidence' => 100, 'stemming' => true,
	 'expect_min' => 1000,
	 'note' => 'Doubled-consonant undo: running also finds run docs'],

	['id' => 'exp-tok-apostrophe',
	 'query' => "don't", 'confidence' => 100,
	 'expect_top' => "Don't Stop", 'expect_min' => 2000,
	 'note' => 'Tokenizer contract: don\'t finds dont (was 0 hits when query and build tokenized differently)'],

	['id' => 'exp-tok-hyphen',
	 'query' => 'coca-cola', 'confidence' => 100,
	 'expect_top' => 'Coca-Cola', 'expect_min' => 400,
	 'note' => 'Tokenizer contract: hyphen splits to coca + cola (was 0 hits)'],

	['id' => 'exp-excl-wildcard',
	 'query' => 'football -americ*', 'confidence' => 100,
	 'expect_min' => 3000,
	 'note' => 'Excluded wildcard is COMPLETE (SQL doc-id sets; the rarest-first cap used to leak common completions)'],

	['id' => 'exp-stem-risky-verified',
	 'query' => 'quickly', 'confidence' => 100, 'stemming' => true,
	 'expect_top' => 'Quick', 'expect_min' => 3500,
	 'note' => 'Risky -ly rule unlocked by OEWN verifier (quickly->quick linked)'],

	['id' => 'exp-stem-risky-guard',
	 'query' => 'summer', 'confidence' => 100, 'stemming' => true,
	 'expect_in_top' => ['Summer'], 'top_n' => 3, 'expect_min' => 1000,
	 'note' => 'Collision guard — summer must NOT stem to "sum" (no OEWN link)'],

	['id' => 'exp-derivations',
	 'query' => 'decision', 'confidence' => 100, 'derivations' => true,
	 'expect_top' => 'Decision', 'expect_min' => 2500,
	 'note' => 'Derivational expansion — decision also searches decide (OEWN link)'],

	['id' => 'exp-excl-phrase-and',
	 'query' => '+"world war" -"cold war"', 'confidence' => 100,
	 'expect_min' => 1000,
	 'note' => 'Excluded phrase = per-field AND group (word-level OR excluded almost everything -> 0 hits)'],

	// Regression pair: the AUTO_PENDING typo-evidence check must read TYPED
	// words only. Stemming generates blind non-word variants
	// ("photosynthesis" -> "photosynthesi", df=0) which an earlier check (over
	// the pre-synonym list, stems included) read as typo evidence, routing
	// stemmed questions to freq — found comparing against live-Wikipedia
	// CirrusSearch ("how does photosynthesis work" returned Science/Chemistry/"Deaths in
	// 2010"). The pair locks BOTH sides: clean stemmed question stays on the
	// question route (topical results), real typos still route to freq.
	['id' => 'exp-auto-stem-question',
	 'query' => 'how does photosynthesis work', 'confidence' => 85,
	 'stemming' => true, 'algo' => 'auto',
	 'expect_in_top' => ['Photosynthesis', 'Plant physiology', 'Biology',
	                     'Chlorophyll', 'Electron transport chain'], 'top_n' => 5,
	 'note' => 'Stem non-words are not typo evidence — question routes bm25f, not freq'],

	['id' => 'exp-auto-typo-still-freq',
	 'query' => 'einstien', 'confidence' => 85,
	 'stemming' => true, 'algo' => 'auto',
	 'expect_top' => 'Albert Einstein',
	 'note' => 'Guard: REAL typo evidence still routes to freq (typo swap intact)'],

	['id' => 'exp-combo-kitchen-sink',
	 'query' => '+einstein^2 relat* -bob "nobel prize"', 'confidence' => 85,
	 'stemming' => true,
	 'expect_top' => 'Albert Einstein', 'expect_min' => 10,
	 // On a positional index "nobel prize" demands ADJACENCY, which
	 // shrinks the result set (docs mentioning both words separately no
	 // longer qualify) — the #1 doc must survive, the count floor drops.
	 'when_positional' => ['expect_min' => 1],
	 'note' => 'All modifiers at once: required+boost, wildcard, excluded, phrase, fuzzy, stemming'],
];

foreach ($expansionTests as $et) {
	$et['algo']  = $et['algo'] ?? 'bm25';
	$et['group'] = 'expansion';
	$tests[] = $et;
}

// ── Section 5d: Positional phrase semantics ──────────────────────────────
// Expectations are GROUND TRUTH measured from the dump itself (all 282,905
// docs streamed through the production tokenizer by a one-off script). For
// each phrase: ADJ = docs where it appears token-adjacent
// in at least one field; SAME = docs with all its words in one field;
// CROSS = docs with all its words somewhere across fields.
//
//   phrase             ADJ     SAME    CROSS
//   solar system       530      687      687
//   black hole         165      288      288
//   coca cola          439      440      440
//   world war ii      4495     5137     5138
//   albert einstein    192      211      211
//   system solar         1      687      687   (reversed-order trap)
//   war world           50     8809     8809   (reversed-order trap)
//   united states    62758    63225    63227
//
// The math that turns these into totals: non-positional +"P" = CROSS (each word
// required, satisfiable in different fields); positional +"P" = ADJ.
// Non-positional -"P" excludes SAME; positional -"P" excludes ADJ. Totals cap at
// candidate_limit (5000): when CROSS > 5000 the coverage prune keeps an
// arbitrary 5000 of the all-words docs, so positional totals there get
// RANGE assertions instead of exact ones (adjacent docs can fall out of
// the pruned set on coverage ties).
//
// Every test declares BOTH expectation sets — the suite is green on both
// index generations, and --save/--compare across a rebuild shows the
// semantic shift as hit-count diffs with zero status churn.
//
// COUNTS ARE BANDS (~±3%), NOT EXACT: while validating these, one doc came
// out ±1 vs ground truth — the index predated the on-disk dump by a day,
// and the "Research" article gained an "albert einstein" mention in
// between. Corpus drift of
// a few docs per re-download is normal; the bands absorb it while staying
// far too narrow to miss a semantics regression (mode deltas are 25-99%).

$positionalTests = [
	// Entity phrase, CROSS < 5000 → both modes exactly predictable.
	['id' => 'pos-entity-phrase',
	 'query' => '+"solar system"', 'confidence' => 100,
	 'expect_top' => 'Solar System',
	 'when_nonpositional'     => ['expect_min' => 670, 'expect_max' => 705],
	 'when_positional' => ['expect_min' => 515, 'expect_max' => 545],
	 'note' => 'Entity phrase: nonpos=CROSS(~687), positional=ADJ(~530)'],

	// Compositional phrase of common words — the classic positional case:
	// 43% of co-occurrence docs do NOT contain the actual phrase.
	['id' => 'pos-compositional-phrase',
	 'query' => '+"black hole"', 'confidence' => 100,
	 'expect_top' => 'Black hole',
	 'when_nonpositional'     => ['expect_min' => 280, 'expect_max' => 297],
	 'when_positional' => ['expect_min' => 160, 'expect_max' => 171],
	 'note' => 'Compositional phrase: nonpos=~288 co-occur, positional=~165 true phrase'],

	// Multi-token normalization inside a quoted phrase: "coca-cola" → the
	// consecutive tokens coca,cola.
	['id' => 'pos-hyphen-phrase',
	 'query' => '+"coca-cola"', 'confidence' => 100,
	 'expect_top' => 'Coca-Cola',
	 'expect_min' => 425, 'expect_max' => 455,
	 'note' => 'Hyphenated phrase normalizes to adjacent tokens (~440 both modes: this phrase is almost always adjacent — the assertion here is normalization, not adjacency)'],

	// Three-word phrase; CROSS(5138) exceeds candidate_limit, so non-positional
	// pins at the cap and positional gets a range (prune-tie subset).
	['id' => 'pos-threeword-phrase',
	 'query' => '+"world war ii"', 'confidence' => 100,
	 'expect_top' => 'World War II',
	 'when_nonpositional'     => ['expect_min' => 4800, 'expect_max' => 5000],
	 'when_positional' => ['expect_min' => 3900, 'expect_max' => 4600],
	 'note' => '3-word phrase at the candidate cap: nonpos=5000(cap), positional≤ADJ(~4495)'],

	// THE flagship trap: reversed word order. Words co-occur in 687 docs;
	// the literal sequence "system solar" exists in exactly ONE document.
	// An engine whose quotes mean adjacency returns 1; one that treats
	// quotes as AND returns 687.
	['id' => 'pos-reversed-order',
	 'query' => '+"system solar"', 'confidence' => 100,
	 'when_nonpositional'     => ['expect_min' => 670, 'expect_max' => 705],
	 'when_positional' => ['expect_min' => 1, 'expect_max' => 4],
	 'note' => 'Reversed-order phrase: ADJ=1 vs CROSS=~687 — quotes must mean order'],

	['id' => 'pos-reversed-trap-cap',
	 'query' => '+"war world"', 'confidence' => 100,
	 'when_nonpositional'     => ['expect_min' => 5000, 'expect_max' => 5000],
	 'when_positional' => ['expect_min' => 1, 'expect_max' => 60],
	 'note' => 'Reversed trap above the cap: nonpos=5000(cap), positional≤ADJ(~50)'],

	// Phrase EXCLUSION precision: 313 docs mention einstein; 211 have
	// albert+einstein in one field but only 192 contain the adjacent name.
	// Non-positional over-excludes the 19 docs in between.
	['id' => 'pos-excl-precision',
	 'query' => '+einstein -"albert einstein"', 'confidence' => 100,
	 'when_nonpositional'     => ['expect_min' => 100, 'expect_max' => 106],
	 'when_positional' => ['expect_min' => 116, 'expect_max' => 126],
	 'note' => 'Exclusion precision: nonpos ~= 313-SAME(210), positional ~= 313-ADJ(192) — positional must exceed non-positional'],

	// The escape hatch: phrase_mode=unordered must reproduce non-positional totals on
	// ANY index — same expectation on both generations, no when_ overrides.
	['id' => 'pos-mode-escape-hatch',
	 'query' => '+"solar system"', 'confidence' => 100,
	 'phrase_mode' => 'unordered',
	 'expect_top' => 'Solar System',
	 'expect_min' => 670, 'expect_max' => 705,
	 'note' => "phrase_mode=unordered forces CROSS(~687) semantics regardless of index"],

	// Capture-only (no assertions): totals shift across the rebuild and
	// --compare surfaces them; exact values depend on cap-tie pruning.
	['id' => 'pos-capture-excl-broad',
	 'query' => 'war -"world war"', 'confidence' => 100,
	 'note' => 'Capture: broad term minus phrase — non-positional excludes SAME(8809), positional ADJ(6531)'],

	['id' => 'pos-capture-slop-filter',
	 'query' => 'volcano -"one of the"', 'confidence' => 100,
	 'note' => 'Capture: slop-style exclusion of a common-word phrase (the curated-web use case)'],

	// Performance: positional verification cost rides on pruned candidates.
	['id' => 'pos-perf-phrase-at-cap',
	 'query' => '+"united states"', 'confidence' => 100,
	 'max_time' => 2.5,
	 'when_nonpositional'     => ['expect_min' => 5000, 'expect_max' => 5000],
	 'when_positional' => ['expect_min' => 4500, 'expect_max' => 5000],
	 'note' => 'Phrase verify at the 5000-candidate cap — position fetch must stay in budget'],

	['id' => 'pos-perf-excl-common-words',
	 'query' => 'war -"such as"', 'confidence' => 100,
	 'when_nonpositional'     => ['max_time' => 4.0],
	 'when_positional' => ['max_time' => 2.0],
	 'note' => 'Common-word phrase exclusion: non-positional fetches full such/as postings, positional fetches candidate-bounded positions'],
];

foreach ($positionalTests as $pt) {
	$pt['algo']  = $pt['algo'] ?? 'bm25';
	$pt['group'] = 'positional';
	$tests[] = $pt;
}

// ── Section 5e: Diacritic folding ────────────────────────────────────────
// The index stores ONE vocabulary shape (meta.tokenizer_name); the searcher
// reads it and folds query terms to match. Pre-folding measurement on this
// corpus: "sao paulo" could not reach the São Paulo article's title tokens
// at all (title df: sao 2, são 87), "pokemon" matched no title. Each test
// carries the folded expectation (the fix) and the unfolded one (either the
// documented shortfall, or no expectation where the unfolded number is just
// "whatever the split vocabulary happens to give").

$foldTests = [
	['id' => 'fold-sao-paulo',
	 'query' => 'sao paulo', 'confidence' => 100,
	 'when_folded'   => ['expect_top' => 'São Paulo', 'expect_min' => 900],
	 'when_unfolded' => ['expect_min' => 1],
	 'note' => 'ASCII spelling reaches the accented title + exact-title bonus'],

	['id' => 'fold-sao-paulo-accented',
	 'query' => 'são paulo', 'confidence' => 100,
	 'expect_top' => 'São Paulo',
	 'note' => 'Accented input works on BOTH vocabularies (folded: query folds too)'],

	['id' => 'fold-pokemon',
	 'query' => 'pokemon', 'confidence' => 100,
	 'when_folded'   => ['expect_top' => 'Pokémon', 'expect_min' => 200],
	 'when_unfolded' => ['expect_min' => 1],
	 'note' => 'Unfolded: title "pokemon" has 0 df; folded: exact title'],

	['id' => 'fold-francois',
	 'query' => 'francois', 'confidence' => 100,
	 'when_folded'   => ['expect_min' => 1000],
	 'when_unfolded' => ['expect_max' => 400],
	 'note' => 'Body df francois 176 vs françois 1010 before folding — recall gap'],

	['id' => 'fold-phrase',
	 'query' => '"sao paulo"', 'confidence' => 100,
	 'when_folded'   => ['expect_top' => 'São Paulo', 'expect_min' => 500],
	 'when_unfolded' => ['expect_min' => 0],
	 'note' => 'Phrase words fold like bare words'],

	['id' => 'fold-wildcard',
	 'query' => 'zuric*', 'confidence' => 100,
	 'when_folded'   => ['expect_in_top' => ['Zürich'], 'top_n' => 3, 'expect_min' => 600],
	 'when_unfolded' => ['expect_min' => 1],
	 'note' => 'Wildcard prefix folds (zuric* reaches zürich)'],

	['id' => 'fold-fuzzy-still-works',
	 'query' => 'pokeman', 'confidence' => 80,
	 'when_folded'   => ['expect_in_top' => ['Pokémon'], 'top_n' => 3],
	 'when_unfolded' => ['expect_min' => 0],
	 'note' => 'Fuzzy over the folded vocabulary: byte-level DL sees 1 edit, not 3'],

	['id' => 'fold-strasse',
	 'query' => 'strasse', 'confidence' => 100,
	 'when_folded'   => ['expect_min' => 50],
	 'when_unfolded' => ['expect_max' => 20],
	 'note' => 'ß → ss (multi-char fold): strasse 11 vs straße 41 before'],

	['id' => 'fold-ascii-unchanged',
	 'query' => 'albert einstein', 'confidence' => 100,
	 'expect_top' => 'Albert Einstein',
	 'note' => 'Pure-ASCII queries are byte-identical under both vocabularies'],
];

foreach ($foldTests as $ft) {
	$ft['algo']  = $ft['algo'] ?? 'bm25';
	$ft['group'] = 'folding';
	$tests[] = $ft;
}

// ── Section 6: Algorithm comparison — same query, all algos ──────────────
// Captures result count + top 5 for a fixed set of queries so we can see
// how ranking differs across algorithms.

$comparisonQueries = [
	['id' => 'cmp-climate', 'query' => 'climate change global warming', 'confidence' => 100],
	['id' => 'cmp-smart',   'query' => 'smart', 'confidence' => 85],
	['id' => 'cmp-quantum', 'query' => 'quantum tunnel', 'confidence' => 85],
	['id' => 'cmp-python',  'query' => '+python programming', 'confidence' => 100],
	['id' => 'cmp-monster', 'query' => 'monster', 'confidence' => 85],
	// Phrase query across all algos — captures how each algorithm ranks
	// under non-positional vs adjacency phrase semantics (diff via --save/--compare
	// across the positional rebuild).
	['id' => 'cmp-phrase-coldwar', 'query' => '+"cold war" europe', 'confidence' => 100],
];

foreach ($comparisonQueries as $cq) {
	foreach ($algos as $algo) {
		$test = $cq;
		$test['id']    = $cq['id'] . '-' . $algo;
		$test['algo']  = $algo;
		$test['group'] = 'comparison';
		// No pass/fail assertions — just capture results for comparison
		$tests[] = $test;
	}
}

// ═════════════════════════════════════════════════════════════════════════════
// Runner
// ═════════════════════════════════════════════════════════════════════════════

// The corpus profile from the shared config, plus the OEWN stem verifier,
// mirroring index.php exactly (the suite must validate what production
// actually runs).
$suiteProfile = $CORPUS['profile'];
$sv = $oewn->derivationVerifier();
if ($sv !== null) $suiteProfile['stem_verifier'] = $sv;
$searcher = new \MultiSearch\Searcher(DB_PATH, $suiteProfile);
// The corpus config names its stopword list (config/stopwords.json ships with
// the repo; point the config at your own list for another corpus). The
// engine itself has no list — language data is app data.
$stopwordsDefault = json_decode(@file_get_contents($CORPUS['stopwords'] ?? ''), true) ?? [];
$hasOewn = $oewn->ready();

$pass    = 0;
$fail    = 0;
$skip    = 0;
$errors  = [];
$results = [];

$totalTests = count($tests);
$groupCounts = [];
foreach ($tests as $t) {
	$g = $t['group'] ?? 'other';
	$groupCounts[$g] = ($groupCounts[$g] ?? 0) + 1;
}

echo "MultiSearch Test Suite\n";
echo str_repeat('=', 76) . "\n";
echo "$totalTests tests: ";
foreach ($groupCounts as $g => $c) echo "$g($c) ";
echo "\nIndex: " . DB_PATH . "  |  positions: " . ($INDEX_POSITIONAL ? 'YES (adjacency phrase semantics)' : 'no (word-level phrase semantics)')
	. "  |  diacritics: " . ($INDEX_FOLDED ? 'FOLDED' : 'kept');
echo "\n" . str_repeat('=', 76) . "\n\n";

$currentGroup = '';

// ── Tokenizer unit checks — pure functions, no index ─────────────────────
// The ONE tokenizer both sides share (tokenization contract, lib header).
// Asserted directly because every ranking test above depends on it silently:
// a folding regression would show up as vague recall drops, not as a
// tokenizer failure. [input, fold?, expected tokens]
$tokenizerCases = [
	['tok-basic',        "Don't stop Coca-Cola",       false, ['dont', 'stop', 'coca', 'cola']],
	['tok-keep-accents', 'São Paulo Straße',           false, ['são', 'paulo', 'straße']],
	['tok-fold-latin1',  'Crème Brûlée São Paulo',     true,  ['creme', 'brulee', 'sao', 'paulo']],
	['tok-fold-multi',   'Straße Ærø Œuvre Þórr',      true,  ['strasse', 'aero', 'oeuvre', 'thorr']],
	['tok-fold-ext',     'Wrocław Đà Nẵng Hồ Chí Minh', true,  ['wroclaw', 'da', 'nang', 'ho', 'chi', 'minh']],
	['tok-fold-decomp',  "e\u{0301}cole",              true,  ['ecole']],          // decomposed é
	['tok-fold-turkish', 'İstanbul',                   true,  ['istanbul']],       // i + U+0307 after lowercasing
	['tok-fold-nonlatin', 'Ελλάδα Москва 東京 हिन्दी',   true,  ['ελλάδα', 'москва', '東京', 'हिन्दी']],
	['tok-fold-ascii-fastpath', 'plain ascii text',    true,  ['plain', 'ascii', 'text']],
];
if (!$doLabeledOnly) {
	echo "── TOKENIZER " . str_repeat('─', 70 - strlen('tokenizer')) . "\n";
	foreach ($tokenizerCases as [$tid, $in, $fold, $want]) {
		$got = \MultiSearch\Searcher::tokenize($in, $fold);
		$ok  = $got === $want;
		$ok ? $pass++ : $fail++;
		if (!$ok) $errors[] = "FAIL $tid: got [" . implode(' ', $got) . "], want [" . implode(' ', $want) . "]";
		printf("  %-6s %-42s %s\n", $fold ? 'fold' : 'plain', $tid, $ok ? 'OK' : 'FAIL   !! got [' . implode(' ', $got) . ']');
		$results[] = ['id' => $tid, 'group' => 'tokenizer', 'algo' => $fold ? 'fold' : 'plain',
			'query' => $in, 'status' => $ok ? 'OK' : 'FAIL', 'total' => count($got), 'elapsed' => 0, 'top5' => $got];
	}
	$totalTests += count($tokenizerCases);
	echo "\n";
}

foreach ($tests as $test) {
	// --labeled-only: skip the declarative suite, run just the labeled study
	if ($doLabeledOnly) break;
	$group = $test['group'] ?? 'other';

	// Positionality-sensitive tests carry BOTH expectation sets;
	// merge the one matching the index under test. Overrides replace any
	// top-level key (expect_*, max_time, even note), so a test stays green
	// and MEANINGFUL on both index generations.
	$modeKey = $INDEX_POSITIONAL ? 'when_positional' : 'when_nonpositional';
	if (isset($test[$modeKey])) $test = array_replace($test, $test[$modeKey]);
	unset($test['when_positional'], $test['when_nonpositional']);
	// Same mechanism for the diacritic-folded vs unfolded vocabulary.
	$foldKey = $INDEX_FOLDED ? 'when_folded' : 'when_unfolded';
	if (isset($test[$foldKey])) $test = array_replace($test, $test[$foldKey]);
	unset($test['when_folded'], $test['when_unfolded']);

	// --fast: skip performance and comparison groups (they're slow and not about ranking quality)
	if ($doFast && in_array($group, ['performance', 'comparison'])) {
		$skip++;
		continue;
	}

	if ($group !== $currentGroup) {
		$currentGroup = $group;
		echo "── " . strtoupper($group) . " " . str_repeat('─', 70 - strlen($group)) . "\n";
	}

	$id    = $test['id'];
	$query = $test['query'];
	$algo  = $test['algo'] ?? 'bm25';
	$conf  = $test['confidence'] ?? 100;

	$searchOpts = [
		'algo'       => $algo,
		'confidence' => $conf,
		'fields'     => $defaultFields,
		'per_page'   => 20,
		'stemming'   => !empty($test['stemming']),
		'stopwords'  => (!empty($test['stopwords'])) ? $stopwordsDefault : [],
	];
	// --rk overrides apply to every search
	if (!empty($CONFIG_RK)) $searchOpts['ranking'] = $CONFIG_RK;
	// phrase_mode passthrough — lets A/B tests force word-level phrase
	// semantics on a positional index.
	if (isset($test['phrase_mode'])) $searchOpts['phrase_mode'] = $test['phrase_mode'];

	// Synonym expansion
	if (!empty($test['synonyms'])) {
		if (!$hasOewn) {
			$skip++;
			$results[] = ['id' => $id, 'status' => 'SKIP', 'reason' => 'oewn.db not found'];
			printf("  %-6s %-42s SKIP (no oewn.db)\n", $algo, $id);
			continue;
		}
		$searchOpts['synonyms'] = $oewn->groupsFor(\MultiSearch\OewnSynonyms::queryWords($query), 1);
	}

	// Derivational expansion (same OEWN dependency as synonyms)
	if (!empty($test['derivations'])) {
		if (!$hasOewn) {
			$skip++;
			$results[] = ['id' => $id, 'status' => 'SKIP', 'reason' => 'oewn.db not found'];
			printf("  %-6s %-42s SKIP (no oewn.db)\n", $algo, $id);
			continue;
		}
		$searchOpts['derivations'] = $oewn->derivationsFor(\MultiSearch\OewnSynonyms::queryWords($query));
	}

	$t0 = microtime(true);
	try {
		$result = $searcher->search($query, $searchOpts);
		$elapsed = microtime(true) - $t0;
	} catch (\Throwable $e) {
		$fail++;
		$errors[] = "CRASH $id: " . $e->getMessage();
		$results[] = ['id' => $id, 'status' => 'CRASH', 'error' => $e->getMessage()];
		printf("  %-6s %-42s CRASH  %s\n", $algo, $id, substr($e->getMessage(), 0, 50));
		continue;
	}

	$total   = $result['total'];
	// Doc ids are opaque integers — assertions and saved results compare
	// display TITLES (from the hit's 'title' key), the same strings the suite
	// compared back when titles were the doc ids.
	$topDocs = array_map(fn($h) => $h['title'], array_values($result['hits']));
	$top5    = array_slice($topDocs, 0, 5);
	$topDoc  = $top5[0] ?? '';

	// Get scores for top 5
	$top5WithScores = [];
	$i = 0;
	foreach ($result['hits'] as $docId => $hit) {
		if ($i >= 5) break;
		$top5WithScores[] = [
			'doc'   => $hit['title'],   // display title, not the opaque doc id
			'score' => round($hit['score'], 4),
		];
		$i++;
	}

	$entry = [
		'id'       => $id,
		'query'    => $query,
		'algo'     => $algo,
		'conf'     => $conf,
		'total'    => $total,
		'elapsed'  => round($elapsed, 4),
		'top5'     => $top5WithScores,
		'status'   => 'OK',
	];
	// The RESOLVED algorithm ('auto' reports its pick) — recorded always,
	// asserted via expect_algo below.
	if ($algo === 'auto') $entry['resolved_algo'] = $result['algo'] ?? '?';

	// ── Assertions ────────────────────────────────────────────────────
	$ok     = true;
	$reason = '';

	if (isset($test['expect_min']) && $total < $test['expect_min']) {
		$ok = false;
		$reason = "expected >={$test['expect_min']} hits, got $total";
	}
	// expect_algo: what 'auto' must resolve to. Use only for expectations
	// that hold under EVERY knob config (clean query -> never freq; a
	// swap-eligible single typo -> freq under all configs), so a config
	// sweep can't trip it by design.
	if (isset($test['expect_algo']) && ($result['algo'] ?? '') !== $test['expect_algo']) {
		$ok = false;
		$reason = "expected auto->'{$test['expect_algo']}', resolved '{$result['algo']}'";
	}
	if (isset($test['expect_max']) && $total > $test['expect_max']) {
		$ok = false;
		$reason = "expected <={$test['expect_max']} hits, got $total";
	}
	if (isset($test['expect_top']) && $topDoc !== $test['expect_top']) {
		$ok = false;
		$reason = "expected #1='{$test['expect_top']}', got '$topDoc'";
	}
	if (isset($test['expect_in_top'])) {
		$topN  = array_slice($topDocs, 0, $test['top_n'] ?? 5);
		$found = false;
		foreach ($test['expect_in_top'] as $expected) {
			if (in_array($expected, $topN)) { $found = true; break; }
		}
		if (!$found) {
			$ok = false;
			$reason = "expected one of [" . implode(', ', $test['expect_in_top'])
				. "] in top " . ($test['top_n'] ?? 5)
				. ", got [" . implode(', ', $topN) . "]";
		}
	}
	if (isset($test['max_time']) && $elapsed > $test['max_time']) {
		$ok = false;
		$reason = "too slow: " . round($elapsed, 2) . "s > {$test['max_time']}s";
	}

	if (!$ok) {
		$fail++;
		$errors[] = "FAIL $id: $reason";
		$entry['status'] = 'FAIL';
		$entry['reason'] = $reason;
		printf("  %-6s %-42s FAIL   %6.3fs  %6d hits  !! %s\n",
			$algo, $id, $elapsed, $total, $reason);
	} else {
		$pass++;
		$entry['status'] = 'OK';
		// For comparison group, show top 3 docs
		if ($group === 'comparison' || $group === 'ranking') {
			$topStr = implode(', ', array_map(
				fn($d) => '"' . mb_substr($d['doc'], 0, 25) . '"(' . $d['score'] . ')',
				array_slice($top5WithScores, 0, 3)
			));
			printf("  %-6s %-42s OK     %6.3fs  %6d hits  %s\n",
				$algo, $id, $elapsed, $total, $topStr);
		} else {
			printf("  %-6s %-42s OK     %6.3fs  %6d hits\n",
				$algo, $id, $elapsed, $total);
		}
	}

	$results[] = $entry;
}

// ── Per-search ranking-override check ────────────────────────────────────
// Ported from the retired tmp-sb.php sandbox (its one assertion no declared
// test covered). The 'ranking' search option must actually reach scoring:
// the same query with one knob changed (stem_boost 0.8 default -> 1.0) must
// produce DIFFERENT scores while matching the SAME documents. Needs two
// searches compared against each other, which the declarative test format
// can't express — hence this one imperative block.
if (!$doLabeledOnly) {
	echo "── RANKING-OVERRIDE " . str_repeat('─', 56) . "\n";
	$t0  = microtime(true);
	$ovOpts = ['algo' => 'bm25', 'confidence' => 100, 'fields' => $defaultFields,
	           'stemming' => true, 'per_page' => 10];
	$ovA = $searcher->search('wolves', $ovOpts);
	$ovB = $searcher->search('wolves', $ovOpts + ['ranking' => ['stem_boost' => 1.0]]);
	$ovElapsed = microtime(true) - $t0;
	$ovScoresA = array_map(fn($h) => $h['score'], array_values($ovA['hits']));
	$ovScoresB = array_map(fn($h) => $h['score'], array_values($ovB['hits']));
	$ovOk = ($ovScoresA !== $ovScoresB) && ($ovA['total'] === $ovB['total']);
	$totalTests++;
	$ovEntry = ['id' => 'feat-ranking-override', 'query' => 'wolves', 'algo' => 'bm25',
		'conf' => 100, 'total' => $ovA['total'], 'elapsed' => round($ovElapsed, 4),
		'top5' => [], 'status' => $ovOk ? 'OK' : 'FAIL'];
	if ($ovOk) {
		$pass++;
		printf("  %-6s %-42s OK     %6.3fs  %6d hits\n", 'bm25', 'feat-ranking-override', $ovElapsed, $ovA['total']);
	} else {
		$fail++;
		$reason = $ovScoresA === $ovScoresB
			? 'stem_boost override did not change scores'
			: "override changed MATCHING ({$ovA['total']} vs {$ovB['total']} hits), not just scoring";
		$errors[] = "FAIL feat-ranking-override: $reason";
		$ovEntry['reason'] = $reason;
		printf("  %-6s %-42s FAIL   %6.3fs  !! %s\n", 'bm25', 'feat-ranking-override', $ovElapsed, $reason);
	}
	$results[] = $ovEntry;
}

// There is deliberately no stopword-list sync check here: one lived here
// briefly, asserting set-equality between the app's JSON list and a list the
// engine used to ship. Removing the engine's copy left one list, and a sync
// check with one list is vacuous.

// ═════════════════════════════════════════════════════════════════════════════
// Labeled ranking study
// ═════════════════════════════════════════════════════════════════════════════
// Ground-truth queries with known best articles, run across ALL algorithms at
// the UI defaults (auto, conf 85, stemming on, stopwords off). Each row
// records the target's RANK — not pass/fail — so saved runs under different
// --rk/--label runs can be compared quantitatively (MRR, top-1 rate).
//
// Every query was verified against the live index before inclusion (word dfs,
// whether a swap fires at baseline, and that the target article exists).
// Categories:
//   absent-typo   typed word df=0 — strongest typo evidence; the historical
//                 swap (df>0 only) had no path for these
//   corpus-typo   typo exists at low df (einstien df=2, girafe df=1)
//   name-bait     rare-but-real names the baseline falsely "corrects"
//                 (rukh->rush 88:1366, virat->viral, un->u 3419:34198,
//                 burj->burn — all reproduce at baseline)
//   swap-harmless a swap fires at baseline but #1 was right anyway; the
//                 fixes must not break these (taj->tag, da->de)
//   ambiguous     defensible either way; measured, never asserted
//   clean-name / clean-topic / phrase
//                 no typos — the do-no-harm set, GUARDED: auto must rank
//                 the target #1 under every config or the run FAILS
//
// Notable authoring finds: no Pelé article exists in this corpus ('pele'
// dropped — no ground truth); 'restraunt' (df=0) keeps target Restaurant even
// though the correction is edit-distance 2, beyond the conf-85 cap for a
// 9-char word — its job is measuring that no config INVENTS an answer
// (before the swap guards the top hit was junk; a zero-df promotion floor
// of 50 promotes the wrong word "restraint" df=76, which is one reason the
// default swap_zero_df_promote is 100).

$labeledQueries = [
	['q' => 'wikipeedia',  'cat' => 'absent-typo', 'targets' => ['Wikipedia']],
	['q' => 'beethovan',   'cat' => 'absent-typo', 'targets' => ['Ludwig van Beethoven', 'Beethoven']],
	['q' => 'pyramyd',     'cat' => 'absent-typo', 'targets' => ['Pyramid']],
	['q' => 'phyisics',    'cat' => 'absent-typo', 'targets' => ['Physics']],
	['q' => 'libary',      'cat' => 'absent-typo', 'targets' => ['Library']],
	['q' => 'restraunt',   'cat' => 'absent-typo', 'targets' => ['Restaurant']],

	['q' => 'einstien',    'cat' => 'corpus-typo', 'targets' => ['Albert Einstein']],
	['q' => 'girafe',      'cat' => 'corpus-typo', 'targets' => ['Giraffe']],
	['q' => 'volcanoe',    'cat' => 'corpus-typo', 'targets' => ['Volcano']],
	['q' => 'shakespear',  'cat' => 'corpus-typo', 'targets' => ['William Shakespeare']],

	['q' => 'shah rukh khan', 'cat' => 'name-bait', 'targets' => ['Shah Rukh Khan']],
	['q' => 'virat kohli',    'cat' => 'name-bait', 'targets' => ['Virat Kohli']],
	['q' => 'kim jong un',    'cat' => 'name-bait', 'targets' => ['Kim Jong-un']],
	['q' => 'burj khalifa',   'cat' => 'name-bait', 'targets' => ['Burj Khalifa']],

	['q' => 'taj mahal',         'cat' => 'swap-harmless', 'targets' => ['Taj Mahal']],
	['q' => 'leonardo da vinci', 'cat' => 'swap-harmless', 'targets' => ['Leonardo da Vinci']],

	['q' => 'chocolat',    'cat' => 'ambiguous', 'targets' => ['Chocolat']],

	['q' => 'lionel messi',    'cat' => 'clean-name', 'targets' => ['Lionel Messi']],
	['q' => 'elon musk',       'cat' => 'clean-name', 'targets' => ['Elon Musk']],
	['q' => 'usain bolt',      'cat' => 'clean-name', 'targets' => ['Usain Bolt']],
	['q' => 'nikola tesla',    'cat' => 'clean-name', 'targets' => ['Nikola Tesla']],
	['q' => 'freddie mercury', 'cat' => 'clean-name', 'targets' => ['Freddie Mercury']],
	['q' => 'aamir khan',      'cat' => 'clean-name', 'targets' => ['Aamir Khan']],
	['q' => 'albert einstein', 'cat' => 'clean-name', 'targets' => ['Albert Einstein']],
	['q' => 'isaac newton',    'cat' => 'clean-name', 'targets' => ['Isaac Newton']],
	['q' => 'genghis khan',    'cat' => 'clean-name', 'targets' => ['Genghis Khan']],

	['q' => 'quantum mechanics',   'cat' => 'clean-topic', 'targets' => ['Quantum mechanics']],
	['q' => 'mount everest',       'cat' => 'clean-topic', 'targets' => ['Mount Everest']],
	['q' => 'amazon river',        'cat' => 'clean-topic', 'targets' => ['Amazon River']],
	['q' => 'solar system',        'cat' => 'clean-topic', 'targets' => ['Solar System']],
	['q' => 'theory of relativity', 'cat' => 'clean-topic', 'targets' => ['Theory of relativity']],
	['q' => 'great wall of china', 'cat' => 'clean-topic', 'targets' => ['Great Wall of China']],
	['q' => 'photosynthesis',      'cat' => 'clean-topic', 'targets' => ['Photosynthesis']],
	['q' => 'gravity',             'cat' => 'clean-topic', 'targets' => ['Gravity']],
	['q' => 'jupiter',             'cat' => 'clean-topic', 'targets' => ['Jupiter']],

	['q' => '"solar system"',        'cat' => 'phrase', 'targets' => ['Solar System']],
	['q' => '"battle of the indus"', 'cat' => 'phrase', 'targets' => ['Battle of the Indus']],
	['q' => '"great wall of china"', 'cat' => 'phrase', 'targets' => ['Great Wall of China']],
];

$LABELED_GUARD_CATS = ['clean-name', 'clean-topic', 'phrase', 'swap-harmless'];
$labeledData = [];
$labeledT0   = microtime(true);

echo "\n── LABELED RANKING STUDY (config: $CONFIG_NAME) "
	. str_repeat('─', max(1, 27 - strlen($CONFIG_NAME))) . "\n";

foreach ($labeledQueries as $lq) {
	foreach ($algos as $la) {
		$opts = [
			'algo'       => $la,
			'confidence' => 85,
			'fields'     => $defaultFields,
			'per_page'   => 50,
			'stemming'   => true,
			'stopwords'  => [],
			'diagnostics'=> ($la === 'auto'),
		];
		if (!empty($CONFIG_RK)) $opts['ranking'] = $CONFIG_RK;
		$t0 = microtime(true);
		try {
			$r = $searcher->search($lq['q'], $opts);
		} catch (\Throwable $e) {
			$labeledData[] = ['query' => $lq['q'], 'cat' => $lq['cat'],
				'algo' => $la, 'rank' => null, 'error' => $e->getMessage()];
			continue;
		}
		$elapsed = microtime(true) - $t0;

		$rank = null;
		$pos  = 0;
		$hits = array_values($r['hits']);
		foreach ($hits as $hit) {
			$pos++;
			if (in_array($hit['title'], $lq['targets'], true)) { $rank = $pos; break; }
		}
		$row = [
			'query'   => $lq['q'],
			'cat'     => $lq['cat'],
			'algo'    => $la,
			'rank'    => $rank,
			'top1'    => $hits[0]['title'] ?? '',
			'elapsed' => round($elapsed, 4),
		];
		if ($la === 'auto') {
			$row['resolved'] = $r['algo'];
			$sw = [];
			foreach ($r['diag']['typo_swaps'] ?? [] as $x) {
				$sw[] = $x['promoted'] === null
					? "{$x['typed']} kept" : "{$x['typed']}->{$x['promoted']}";
			}
			if ($sw) $row['swaps'] = implode(',', $sw);
		}
		$labeledData[] = $row;

		// Do-no-harm guard: on auto, guarded categories must stay #1.
		if ($la === 'auto' && in_array($lq['cat'], $LABELED_GUARD_CATS, true)) {
			$totalTests++;
			if ($rank === 1) {
				$pass++;
			} else {
				$fail++;
				$got = $hits[0]['title'] ?? '(none)';
				$errors[] = "FAIL labeled-guard '{$lq['q']}' [{$lq['cat']}]: "
					. "expected #1 in [" . implode('|', $lq['targets'])
					. "], got '$got'" . ($rank ? " (target at #$rank)" : ' (target unranked)');
			}
		}
	}
	// One compact line per query: auto's behavior + per-algo target ranks.
	$autoRow = null;
	$rankStr = [];
	foreach ($labeledData as $d) {
		if ($d['query'] !== $lq['q']) continue;
		if ($d['algo'] === 'auto') $autoRow = $d;
		else $rankStr[] = substr($d['algo'], 0, 4) . ':' . ($d['rank'] ?? '-');
	}
	printf("  %-22s %-13s auto=%-8s r%-3s %s%s\n",
		mb_substr($lq['q'], 0, 22), $lq['cat'],
		$autoRow['resolved'] ?? '?',
		$autoRow['rank'] ?? '-',
		implode(' ', $rankStr),
		isset($autoRow['swaps']) ? '  [' . $autoRow['swaps'] . ']' : '');
}

// MRR summary: mean(1/rank) per category x algo, unranked target counts 0.
$mrr = [];   // cat => algo => [sum, n]
foreach ($labeledData as $d) {
	if (isset($d['error'])) continue;
	$c = $d['cat']; $a = $d['algo'];
	$mrr[$c][$a]['sum'] = ($mrr[$c][$a]['sum'] ?? 0) + ($d['rank'] ? 1 / $d['rank'] : 0);
	$mrr[$c][$a]['n']   = ($mrr[$c][$a]['n'] ?? 0) + 1;
}
$labeledSummary = [];
echo "\n  MRR by category (config: $CONFIG_NAME)\n";
printf("  %-14s", 'category');
foreach ($algos as $a) printf(" %8s", substr($a, 0, 8));
echo "\n";
foreach ($mrr as $c => $byAlgo) {
	printf("  %-14s", $c);
	foreach ($algos as $a) {
		$cell = isset($byAlgo[$a]['n']) && $byAlgo[$a]['n'] > 0
			? $byAlgo[$a]['sum'] / $byAlgo[$a]['n'] : null;
		$labeledSummary[$c][$a] = $cell === null ? null : round($cell, 3);
		printf(" %8s", $cell === null ? '-' : number_format($cell, 3));
	}
	echo "\n";
}
// Macro-MRR (mean of category MRRs — categories weigh equally regardless of size)
printf("  %-14s", 'MACRO');
foreach ($algos as $a) {
	$vals = [];
	foreach ($labeledSummary as $c => $row) {
		if ($c === 'ambiguous') continue;   // no agreed ground truth
		if (isset($row[$a])) $vals[] = $row[$a];
	}
	$m = $vals ? array_sum($vals) / count($vals) : null;
	$labeledSummary['MACRO'][$a] = $m === null ? null : round($m, 3);
	printf(" %8s", $m === null ? '-' : number_format($m, 3));
}
echo "\n  Labeled study: " . count($labeledQueries) . " queries x " . count($algos)
	. " algos in " . round(microtime(true) - $labeledT0, 1) . "s\n";

// ═════════════════════════════════════════════════════════════════════════════
// Summary
// ═════════════════════════════════════════════════════════════════════════════

echo "\n" . str_repeat('=', 76) . "\n";
echo "Results: $pass OK, $fail FAIL, $skip SKIP / $totalTests total\n";

if (!empty($errors)) {
	echo "\nFailures:\n";
	foreach ($errors as $e) echo "  $e\n";
}

// Timing summary
$timings = array_filter($results, fn($r) => isset($r['elapsed']));
usort($timings, fn($a, $b) => ($b['elapsed'] ?? 0) <=> ($a['elapsed'] ?? 0));
echo "\nSlowest 10:\n";
foreach (array_slice($timings, 0, 10) as $t) {
	printf("  %7.3fs  %6d hits  %-6s  %s\n",
		$t['elapsed'], $t['total'], $t['algo'], $t['id']);
}

$allTimes  = array_column($timings, 'elapsed');
$totalTime = array_sum($allTimes);
$avgTime   = count($allTimes) > 0 ? $totalTime / count($allTimes) : 0;
echo "\nTotal: " . round($totalTime, 2) . "s  |  Avg: " . round($avgTime, 3)
	. "s  |  Peak mem: " . round(memory_get_peak_usage(true) / 1024 / 1024, 1) . " MB\n";

// ═════════════════════════════════════════════════════════════════════════════
// Save results
// ═════════════════════════════════════════════════════════════════════════════

if ($doSave) {
	if (!is_dir(RESULTS_DIR)) mkdir(RESULTS_DIR, 0755, true);

	$runData = [
		'timestamp' => date('c'),
		'index_positional' => $INDEX_POSITIONAL,   // phrase semantics of this run
		'index_folded'     => $INDEX_FOLDED,       // vocabulary shape of this run
		'index_path'       => DB_PATH,
		'config'    => $CONFIG_NAME,               // --label (or 'baseline' / 'rk')
		'ranking_overrides' => $CONFIG_RK,
		'pass'      => $pass,
		'fail'      => $fail,
		'skip'      => $skip,
		'total'     => $totalTests,
		'total_time' => round($totalTime, 3),
		'avg_time'   => round($avgTime, 4),
		'peak_mem_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
		'results'   => $results,
		'labeled'     => $labeledData,
		'labeled_mrr' => $labeledSummary,
	];

	$filename = date('Y-m-d_His') . '_' . $CONFIG_NAME . '.json';
	$path = RESULTS_DIR . '/' . $filename;
	file_put_contents($path, json_encode($runData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
	echo "\nSaved: $path\n";
}

// ═════════════════════════════════════════════════════════════════════════════
// Compare saved runs
// ═════════════════════════════════════════════════════════════════════════════

if ($doCompare) {
	if (!is_dir(RESULTS_DIR)) {
		echo "\nNo saved results to compare. Run with --save first.\n";
		exit(0);
	}

	$files = glob(RESULTS_DIR . '/*.json');
	sort($files); // chronological
	$files = array_slice($files, -$compareN);

	if (count($files) < 2) {
		echo "\nNeed at least 2 saved runs to compare. Found " . count($files) . ".\n";
		exit(0);
	}

	$runs = [];
	foreach ($files as $f) {
		$runs[] = json_decode(file_get_contents($f), true);
	}

	echo "\n" . str_repeat('=', 76) . "\n";
	echo "COMPARISON: " . count($runs) . " runs\n";
	echo str_repeat('=', 76) . "\n\n";

	// Header
	$labels = [];
	foreach ($runs as $i => $run) {
		$ts = substr($run['timestamp'] ?? 'unknown', 0, 19);
		$labels[] = $ts;
		printf("  Run %d: %s  (%d OK, %d FAIL, %.1fs total, %s)\n",
			$i + 1, $ts, $run['pass'], $run['fail'], $run['total_time'],
			!isset($run['index_positional']) ? 'positions: unknown'
				: ($run['index_positional'] ? 'positional index' : 'non-positional index'));
	}

	// Build indexed results per run
	$indexed = [];
	foreach ($runs as $ri => $run) {
		foreach ($run['results'] as $r) {
			$indexed[$ri][$r['id']] = $r;
		}
	}

	// Find differences
	$diffs = [];
	$first = $indexed[0] ?? [];
	$last  = $indexed[count($runs) - 1] ?? [];

	foreach ($last as $id => $cur) {
		$prev = $first[$id] ?? null;
		if (!$prev) continue;

		$changed = false;
		$diff = ['id' => $id, 'algo' => $cur['algo'] ?? '?'];

		// Status change
		if ($prev['status'] !== $cur['status']) {
			$diff['status'] = $prev['status'] . ' → ' . $cur['status'];
			$changed = true;
		}

		// Ranking change
		$prevTop = $prev['top5'][0]['doc'] ?? '';
		$curTop  = $cur['top5'][0]['doc'] ?? '';
		if ($prevTop !== $curTop) {
			$diff['top1'] = '"' . mb_substr($prevTop, 0, 20) . '" → "' . mb_substr($curTop, 0, 20) . '"';
			$changed = true;
		}

		// Total hits change
		if (abs(($prev['total'] ?? 0) - ($cur['total'] ?? 0)) > 0) {
			$diff['hits'] = ($prev['total'] ?? 0) . ' → ' . ($cur['total'] ?? 0);
			$changed = true;
		}

		// Timing change (>20% difference)
		$prevTime = $prev['elapsed'] ?? 0;
		$curTime  = $cur['elapsed'] ?? 0;
		if ($prevTime > 0 && abs($curTime - $prevTime) / $prevTime > 0.2) {
			$pct = round(($curTime - $prevTime) / $prevTime * 100);
			$diff['time'] = round($prevTime, 3) . 's → ' . round($curTime, 3) . "s ({$pct}%)";
			$changed = true;
		}

		if ($changed) $diffs[] = $diff;
	}

	if (empty($diffs)) {
		echo "\nNo differences found between runs.\n";
	} else {
		echo "\n" . count($diffs) . " tests changed:\n\n";
		foreach ($diffs as $d) {
			printf("  %-6s %s\n", $d['algo'] ?? '', $d['id']);
			if (isset($d['status'])) echo "         status: {$d['status']}\n";
			if (isset($d['top1']))   echo "         top #1: {$d['top1']}\n";
			if (isset($d['hits']))   echo "         hits:   {$d['hits']}\n";
			if (isset($d['time']))   echo "         time:   {$d['time']}\n";
		}
	}
}

echo "\nDone. \n";
