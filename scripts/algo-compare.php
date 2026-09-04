<?php
/*
scripts/algo-compare.php — "what does the engine produce?" — every algorithm
side by side on a query, so a human can SEE where they agree and disagree.

This is the exploration tool. The test suite answers "does it produce what I
expected?" (assertions, guards, MRR over known answers); this one is for the
step before that — looking at a query you're curious about, deciding what the
right answer is, and then recording it in the suite's labeled study. The
--target flag is the handshake between the two.

Run:
  php scripts/algo-compare.php                          built-in query set
  php scripts/algo-compare.php 'beyonce' 'kim jong un'  your queries (conf 85)
  php scripts/algo-compare.php --conf=100 'solar system'
  php scripts/algo-compare.php --rk='{"swap_orig_df_max":0}' 'shah rukh khan'
                                                        ranking-knob overrides
  php scripts/algo-compare.php --target='Pelé' 'pele'   where a known answer
                                                        ranks under each algo,
                                                        + a labeled-study row
  php scripts/algo-compare.php --brief 'q1' 'q2'        one line per query:
                                                        word dfs, auto's pick,
                                                        swaps, top 3

Output per query: a grid — one row per algorithm, columns #1..#5 by title.
The title most algorithms put at #1 is the CONSENSUS; rows that disagree
with it are marked '*' in the first column. Disagreement is the signal worth
a human's attention; agreement is noise.

Search options are the UI defaults (stemming on, stopwords off, synonyms
off) so the grid shows what users see. The auto row prints its resolved pick.

History: earlier versions printed top-3 per algorithm as stacked text for a
fixed query list — fine for a one-time benchmark, useless for "what about
THIS query?". The current form is interactive (queries as arguments, a grid
instead of stacked text) and absorbed a separate one-line probe script — now
--brief, the probe that authored the labeled study.
*/

ini_set('memory_limit', '1G');   // required common-word phrases pull ~1M postings

require_once __DIR__ . '/../lib/MultiSearch.php';

$CORPUS = require __DIR__ . '/../config/corpus-wikipedia.php';
// --db=path — compare against another index build (A/B a rebuild against the live index).
foreach ($argv as $arg) if (str_starts_with($arg, '--db=')) $CORPUS['db'] = substr($arg, 5);
if (!file_exists($CORPUS['db'])) { fwrite(STDERR, "DB not found: {$CORPUS['db']}\n"); exit(1); }

// ── Arguments ──────────────────────────────────────────────────────────────
// Anything that isn't a --flag is a query. --conf applies to the ad-hoc
// queries that FOLLOW it (so '--conf=100 a --conf=85 b' works); the built-in
// set carries its own per-query confidence.
$rk = []; $target = null; $brief = false; $conf = 85; $queries = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--rk='))          $rk = json_decode(substr($arg, 5), true) ?: [];
    elseif (str_starts_with($arg, '--target='))  $target = substr($arg, 9);
    elseif (str_starts_with($arg, '--conf='))    $conf = max(0, min(100, (int)substr($arg, 7)));
    elseif ($arg === '--brief')                  $brief = true;
    elseif (str_starts_with($arg, '--db='))      { /* handled above */ }
    else                                         $queries[] = [$arg, $conf, 'ad-hoc'];
}

// Built-in set, used when no queries are given: one of each query SHAPE the
// engine treats differently (auto's routing distinguishes single/multi-word,
// question-shaped, fuzzy/typo, wildcard; phrases exercise the positional
// path). A quick "has anything moved?" sweep after an engine change.
if (empty($queries)) {
    $queries = [
        ['water',                       100, 'single-common'],
        ['einstein',                    100, 'single-proper'],
        ['photosynthesis',              100, 'single-technical'],
        ['monster',                     100, 'single-ambiguous'],
        ['albert einstein',             100, 'multi-name'],
        ['history of france',           100, 'multi-topic'],
        ['solar system planets',        100, 'multi-science'],
        ['world war two',               100, 'multi-event'],
        ['python programming language', 100, 'multi-specific'],
        ['cat dog bird fish',           100, 'multi-breadth'],
        ['what is democracy',           100, 'question-what'],
        ['who invented telephone',      100, 'question-who'],
        ['how does gravity work',       100, 'question-how'],
        ['einsten',                      85, 'fuzzy-typo'],
        ['photosinthesis',               85, 'fuzzy-technical'],
        ['amrican revolution',           85, 'fuzzy-event'],
        ['climate chagne effects',       85, 'fuzzy-multi'],
        ['shah rukh khan',               85, 'fuzzy-rare-name'],
        ['mars +planet',                100, 'boosted-required'],
        ['python +programming -snake',  100, 'mixed-req-excl'],
        // Positional probes: on a positional index these verify adjacency; on a
        // non-positional one they fall back to all-words-required. "one of the"
        // is deliberately the worst case — required common words pull ~1M
        // postings regardless of phrase semantics (hence the 1G memory limit).
        ['+"cold war"',                 100, 'phrase-entity'],
        ['+"world war" -"cold war"',    100, 'phrase-req-excl'],
        ['+"ice cream"',                100, 'phrase-compositional'],
        ['+"war world"',                100, 'phrase-reversed-trap'],
        ['war -"world war"',            100, 'phrase-exclusion'],
        ['"one of the"',                100, 'phrase-common-words'],
    ];
}

$searcher = new \MultiSearch\Searcher($CORPUS['db'], $CORPUS['profile']);
$fields   = $CORPUS['weights'];
$algos    = \MultiSearch\Searcher::ALGOS;   // the engine's own roster, auto first
$TOP      = 5;

// ── Header: the actual runtime identity, never retyped literals ────────────
// (An older version hardcoded "Fields: title=3.0..." as text while the real
// weights came from config — exactly the drift the shared config exists to
// prevent. Everything printed here is read from where the engine reads it.)
$meta = [];
try {
    $pdo = new PDO("sqlite:{$CORPUS['db']}", null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    foreach ($pdo->query("SELECT key, value FROM meta") as $r) $meta[$r['key']] = $r['value'];
} catch (Exception $e) { $pdo = null; }   // no meta table: header says 'unknown', brief mode skips dfs
printf("MultiSearch v%s  |  %s  |  index built %s, positions %s\n",
    \MultiSearch\Searcher::VERSION, $CORPUS['name'] ?? basename($CORPUS['db']),
    $meta['built_at'] ?? 'unknown', ($meta['positions'] ?? '0') === '1' ? 'yes' : 'no');
// NB: concatenate the × — inside a double-quoted string PHP reads "$f×" as
// one identifier (bytes 0x80-0xFF are legal in variable names).
printf("weights %s  |  stemming on, stopwords off  |  knobs: %s%s\n\n",
    implode(' ', array_map(fn($f, $w) => $f . '×' . $w, array_keys($fields), $fields)),
    $rk ? json_encode($rk) : 'engine defaults',
    $target !== null ? "  |  target: \"$target\"" : '');

// ── Helpers ────────────────────────────────────────────────────────────────

/** One search with the UI-default options; elapsed seconds added to the result. */
function run(\MultiSearch\Searcher $s, string $q, string $algo, int $conf, array $fields, array $rk, int $n, bool $diag): array {
    $t0 = microtime(true);
    $r = $s->search($q, ['algo' => $algo, 'confidence' => $conf, 'fields' => $fields, 'per_page' => $n,
        'stemming' => true, 'stopwords' => [], 'ranking' => $rk, 'diagnostics' => $diag]);
    $r['elapsed'] = microtime(true) - $t0;
    return $r;
}

/** Typo-swap events from the diagnostics channel: "rukh→rush" fired,
 *  "rukh kept" blocked by the context check. Empty when none. */
function swaps(array $r): string {
    $out = [];
    foreach ($r['diag']['typo_swaps'] ?? [] as $x) {
        $out[] = $x['promoted'] === null ? "{$x['typed']} kept" : "{$x['typed']}→{$x['promoted']}";
    }
    return $out ? implode(', ', $out) : '';
}

/** 1-based rank of $target's title in the hits, or null if absent. */
function rankOf(array $r, ?string $target): ?int {
    if ($target === null) return null;
    $i = 0;
    foreach ($r['hits'] as $h) { $i++; if ($h['title'] === $target) return $i; }
    return null;
}

/** Fixed-width cell padded by DISPLAY width — printf's %-Ns pads by bytes,
 *  so '→', '…' and CJK titles would misalign the grid. */
function cell(string $s, int $w): string {
    $s = mb_strimwidth($s, 0, $w - 1, '…');
    return $s . str_repeat(' ', max(0, $w - mb_strwidth($s)));
}

/** Per-word document frequency summed over the active fields — the number
 *  the typo-swap heuristic reasons about, so it belongs next to the swap. */
function wordDfs(?PDO $pdo, string $q, array $fields): string {
    if ($pdo === null) return '';
    $out = [];
    foreach (\MultiSearch\Searcher::tokenize($q) as $w) {
        $df = 0;
        foreach (array_keys($fields) as $f) {
            $st = $pdo->prepare("SELECT doc_freq FROM termstats_$f WHERE term = ?");
            $st->execute([$w]);
            $df += (int)$st->fetchColumn();
        }
        $out[] = "$w=$df";
    }
    return implode(' ', $out);
}

// ── Brief mode: one line per query, auto only (the old probe-query.php) ────
// For scanning many candidate queries quickly: which words are rare, what
// auto picked, whether a swap fired, and the top 3. This is how the labeled
// study's queries were vetted before their expected answers were recorded.
if ($brief) {
    foreach ($queries as [$q, $c]) {
        $r = run($searcher, $q, 'auto', $c, $fields, $rk, 3, true);
        $top = array_map(fn($h) => $h['title'] . '(' . round($h['score'], 1) . ')', array_slice(array_values($r['hits']), 0, 3));
        printf("%-24s df[%s]  auto=%-8s %s%s\n    -> %s\n", mb_strimwidth($q, 0, 24, '…'), wordDfs($pdo, $q, $fields),
            $r['algo'], swaps($r) ? '[' . swaps($r) . '] ' : '',
            $target !== null ? 'target@' . (rankOf($r, $target) ?? '-') : '',
            $top ? implode(' | ', $top) : '(no results)');
    }
    exit(0);
}

// ── Grid mode ──────────────────────────────────────────────────────────────
$W = 22;          // title column width; 5 columns + labels fits a 140-col terminal
$labelRows = [];  // --target: labeled-study rows to print at the end
foreach ($queries as [$q, $c, $label]) {
    // Run every algorithm. Diagnostics only on the auto row — that's where the
    // swap/routing story is, and it keeps the other rows' timings honest.
    $rows = [];
    foreach ($algos as $algo) {
        $rows[$algo] = run($searcher, $q, $algo, $c, $fields, $rk, $TOP, $algo === 'auto');
    }

    // Consensus = the most common #1 title among the eight real algorithms
    // (auto is excluded — it IS one of them, and would double-count its pick).
    $firsts = [];
    foreach ($rows as $algo => $r) {
        if ($algo === 'auto') continue;
        $t = array_values($r['hits'])[0]['title'] ?? '';
        $firsts[$t] = ($firsts[$t] ?? 0) + 1;
    }
    arsort($firsts);
    $consensus = array_key_first($firsts) ?? '';
    $agree = $consensus === '' ? 0 : $firsts[$consensus];

    // Query header: what auto did, any swap, and how unanimous the #1 is.
    $sw = swaps($rows['auto']);
    printf("[%s] \"%s\"  conf %d  |  auto→%s%s  |  consensus #1: %s (%d/%d)\n",
        $label, $q, $c, $rows['auto']['algo'], $sw ? "  |  swap: $sw" : '',
        $consensus === '' ? '(none)' : $consensus, $agree, count($algos) - 1);
    echo '  ' . cell('algo', 12) . cell('hits', 7) . cell('ms', 6);
    for ($i = 1; $i <= $TOP; $i++) echo cell("#$i", $W);
    if ($target !== null) echo 'target@';
    echo "\n";

    // One row per algorithm. '*' = this algorithm's #1 is not the consensus;
    // '▶' prefixes the target wherever it appears in the top 5.
    $targetSeen = false;
    foreach ($rows as $algo => $r) {
        $hits  = array_values($r['hits']);
        $first = $hits[0]['title'] ?? '';
        $mark  = ($first !== $consensus) ? '*' : ' ';
        $name  = $algo === 'auto' ? 'auto→' . $r['algo'] : $algo;
        echo $mark . ' ' . cell($name, 12) . cell((string)$r['total'], 7) . cell((string)round($r['elapsed'] * 1000), 6);
        for ($i = 0; $i < $TOP; $i++) {
            $t = $hits[$i]['title'] ?? '—';
            echo cell(($target !== null && $t === $target ? '▶' : '') . $t, $W);
        }
        if ($target !== null) {
            $rank = rankOf($r, $target);
            if ($rank !== null) $targetSeen = true;
            echo $rank ?? '-';
        }
        echo "\n";
    }
    // Only queries where the target actually surfaced get a labeled row —
    // a target no algorithm finds within the page is either the wrong title
    // or a bug worth investigating first, not a row worth recording.
    if ($target !== null && $targetSeen) {
        $labelRows[] = sprintf("['q' => %s, 'cat' => '?', 'targets' => [%s]],",
            var_export($q, true), var_export($target, true));
    }
    echo "\n";
}

if ($labelRows) {
    echo "Labeled-study rows (paste into scripts/test-suite.php, set 'cat'):\n";
    foreach ($labelRows as $l) echo "  $l\n";
}
