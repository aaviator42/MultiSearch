<?php
/*
scripts/compare-configs.php — compare labeled-study results across knob
runs — each saved with --label (and usually --rk overrides).

Reads every saved run in data/test-results/, keeps the LATEST run per config
name, and reports:
  1. regression gate    declarative pass/fail per config (a knob that breaks
                        existing behavior shows up here)
  2. MRR matrix         config x category, on the auto algorithm
  3. per-query detail   target rank under every config (auto), for the
                        categories the experiment is about
  4. algo matrix        category x algorithm MRR for a chosen config
                        (--algo-matrix <config>, default all-on)

Run:  php scripts/compare-configs.php
      php scripts/compare-configs.php --algo-matrix combo-fix
*/

$CORPUS = require __DIR__ . '/../config/corpus-wikipedia.php';
$dir = dirname($CORPUS['db']) . '/test-results';

$algoMatrixFor = 'all-on';
foreach ($argv as $i => $a) {
	if ($a === '--algo-matrix' && isset($argv[$i + 1])) $algoMatrixFor = $argv[$i + 1];
}

$runs = [];  // config => runData (latest per config)
foreach (glob("$dir/*.json") ?: [] as $f) {
	$d = json_decode(file_get_contents($f), true);
	if (!isset($d['config'], $d['labeled'])) continue;   // pre-experiment save
	$prev = $runs[$d['config']] ?? null;
	if ($prev === null || strcmp($d['timestamp'], $prev['timestamp']) > 0) {
		$runs[$d['config']] = $d;
	}
}
if (empty($runs)) {
	fwrite(STDERR, "No labeled-study runs found in $dir. Run test-suite.php --save (optionally --rk='{...}' --label=<name>) first.\n");
	exit(1);
}
ksort($runs);
if (isset($runs['baseline'])) $runs = ['baseline' => $runs['baseline']] + $runs;
$configs = array_keys($runs);

// ── 1. Regression gate ────────────────────────────────────────────────────
echo "REGRESSION GATE (declarative suite + do-no-harm guards)\n";
echo str_repeat('=', 72) . "\n";
foreach ($runs as $name => $d) {
	$guardFails = [];
	foreach ($d['results'] ?? [] as $r) {
		if (($r['status'] ?? '') === 'FAIL') $guardFails[] = $r['id'];
	}
	printf("  %-14s %3d OK %3d FAIL  %s\n", $name, $d['pass'], $d['fail'],
		$d['fail'] ? '!! ' . implode(', ', array_slice($guardFails, 0, 6)) : '');
}

// ── 2. MRR matrix: config x category (auto) ───────────────────────────────
// Recomputed from the raw labeled rows restricted to algo=auto, so this table
// is auto's real end-to-end quality per config (routing included).
$cats = [];
$autoMrr = [];   // config => cat => mrr
foreach ($runs as $name => $d) {
	$agg = [];
	foreach ($d['labeled'] as $row) {
		if (($row['algo'] ?? '') !== 'auto' || isset($row['error'])) continue;
		$c = $row['cat'];
		$cats[$c] = true;
		$agg[$c]['sum'] = ($agg[$c]['sum'] ?? 0) + (($row['rank'] ?? null) ? 1 / $row['rank'] : 0);
		$agg[$c]['n']   = ($agg[$c]['n'] ?? 0) + 1;
	}
	foreach ($agg as $c => $v) $autoMrr[$name][$c] = $v['n'] ? $v['sum'] / $v['n'] : null;
}
$cats = array_keys($cats);

echo "\nMRR BY CONFIG x CATEGORY (algo=auto; MACRO excludes 'ambiguous')\n";
echo str_repeat('=', 72) . "\n";
printf("  %-14s", 'config');
foreach ($cats as $c) printf(" %10s", substr($c, 0, 10));
printf(" %10s\n", 'MACRO');
foreach ($autoMrr as $name => $byCat) {
	printf("  %-14s", $name);
	$vals = [];
	foreach ($cats as $c) {
		$v = $byCat[$c] ?? null;
		if ($v !== null && $c !== 'ambiguous') $vals[] = $v;
		printf(" %10s", $v === null ? '-' : number_format($v, 3));
	}
	printf(" %10s\n", $vals ? number_format(array_sum($vals) / count($vals), 3) : '-');
}

// ── 3. Per-query detail for the experiment categories (auto) ─────────────
$detailCats = ['absent-typo', 'corpus-typo', 'name-bait', 'swap-harmless', 'ambiguous'];
echo "\nTARGET RANK PER CONFIG (algo=auto; '-' = target not in top 50)\n";
echo str_repeat('=', 72) . "\n";
$queries = [];   // ordered unique queries in detail cats, from first run
foreach (reset($runs)['labeled'] as $row) {
	if (($row['algo'] ?? '') === 'auto' && in_array($row['cat'], $detailCats, true)
		&& !isset($queries[$row['query']])) {
		$queries[$row['query']] = $row['cat'];
	}
}
printf("  %-20s %-13s", 'query', 'category');
foreach ($configs as $name) printf(" %9s", substr($name, 0, 9));
echo "\n";
foreach ($queries as $q => $cat) {
	printf("  %-20s %-13s", mb_substr($q, 0, 20), $cat);
	foreach ($runs as $d) {
		$cell = '-';
		foreach ($d['labeled'] as $row) {
			if ($row['query'] === $q && ($row['algo'] ?? '') === 'auto') {
				$cell = $row['rank'] ?? '-';
				if (isset($row['swaps']) && $cell !== 1) $cell .= '*';
				break;
			}
		}
		printf(" %9s", $cell);
	}
	echo "\n";
}
echo "  (* = a typo-swap fired on that run)\n";

// ── 4. Algo matrix for one config ─────────────────────────────────────────
if (isset($runs[$algoMatrixFor]['labeled_mrr'])) {
	echo "\nMRR BY CATEGORY x ALGORITHM (config: $algoMatrixFor)\n";
	echo str_repeat('=', 72) . "\n";
	$m = $runs[$algoMatrixFor]['labeled_mrr'];
	$algoNames = array_keys(reset($m));
	printf("  %-14s", 'category');
	foreach ($algoNames as $a) printf(" %8s", substr($a, 0, 8));
	echo "\n";
	foreach ($m as $c => $row) {
		printf("  %-14s", $c);
		foreach ($algoNames as $a) {
			printf(" %8s", isset($row[$a]) ? number_format($row[$a], 3) : '-');
		}
		echo "\n";
	}
} else {
	echo "\n(no run saved for --algo-matrix '$algoMatrixFor')\n";
}
