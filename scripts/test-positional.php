<?php
/*
scripts/test-positional.php — positional phrase verification, fixture tests.
Self-contained: builds tiny throwaway indexes with MultiBuilder (one
positional, one non-positional) and asserts the adjacency semantics against
both. Does NOT touch data/wikipedia.db — the main suite covers whichever
semantics that index has and must stay green unchanged.

Run:  php scripts/test-positional.php
*/

error_reporting(E_ALL);
require __DIR__ . '/../lib/MultiSearch.php';
require __DIR__ . '/../lib/MultiBuilder.php';

use MultiSearch\Searcher;
use MultiSearch\Builder;

$fails = 0;
function check(string $name, bool $ok, string $detail = ''): void {
	global $fails;
	if (!$ok) $fails++;
	echo ($ok ? 'OK   ' : 'FAIL ') . $name . ($detail !== '' ? "  [$detail]" : '') . "\n";
}
function hitTitles(array $r): array {
	return array_map(fn($h) => $h['title'], array_values($r['hits']));
}

// ── Codec round-trip ───────────────────────────────────────────────────────
$cases = [
	[],                       // empty
	[0],                      // position 0 → first delta 0 → NUL byte
	[0, 1, 2, 3],
	[5, 9, 300, 301],         // >127 delta → multi-byte varint
	[1000000],                // large absolute
	[127, 128, 255, 256, 16383, 16384],  // varint boundaries
];
foreach ($cases as $i => $c) {
	$rt = Searcher::decodePositions(Searcher::encodePositions($c));
	check("codec round-trip #$i", $rt === $c, json_encode($c) . ' -> ' . json_encode($rt));
}
check('codec: null decodes to []', Searcher::decodePositions(null) === []);
check('codec: NUL byte survives blob', strlen(Searcher::encodePositions([0])) === 1);

// ── Build fixtures ─────────────────────────────────────────────────────────
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'msearch-postest-' . getmypid();
@mkdir($dir);
$posDb = "$dir/pos.db";
$nonposDb = "$dir/leg.db";
foreach ([$posDb, $nonposDb] as $f) foreach ([$f, "$f-wal", "$f-shm"] as $g) @unlink($g);

$DOCS = [
	// [id, title, body]
	['1', 'World War II',      'the second world war was a global conflict'],
	['2', 'Hello World',       'the war began in spring'],                          // cross-field trap
	['3', 'Geography',         'the world is large and the war is far away'],       // same-field, non-adjacent
	['4', 'Cold War',          'the cold war era of history'],
	['5', 'New New York',      'new new york is a city in futurama'],               // repeated word
	['6', 'Rail Lines',        'the new york new haven railroad line'],
	['7', 'Soft Drinks',       'the coca cola company makes drinks'],               // multi-token normalization
	['8', 'Scattered Cola',    'coca farming and a cola company elsewhere'],
];

foreach ([[true, $posDb], [false, $nonposDb]] as [$withPos, $path]) {
	$b = new Builder($path, ['positions' => $withPos]);
	foreach ($DOCS as [$id, $title, $body]) {
		$b->addText($id, ['title' => $title, 'body' => $body], $title, $body);
	}
	$b->rebuildStats();
	check(($withPos ? 'positional' : 'non-positional') . ' builder hasPositions() = ' . var_export($withPos, true),
		$b->hasPositions() === $withPos);
	$meta = (new PDO("sqlite:$path"))->query("SELECT value FROM meta WHERE key='positions'")->fetchColumn();
	check(($withPos ? 'positional' : 'non-positional') . ' meta.positions recorded', $meta === ($withPos ? '1' : '0'), "meta=$meta");
}

$PROFILE = ['title_field' => 'title', 'field_b' => ['title' => 0.3, 'body' => 0.75]];
$FIELDS  = ['fields' => ['title' => 2.0, 'body' => 1.0]];
$sp = new Searcher($posDb, $PROFILE);
$sl = new Searcher($nonposDb, $PROFILE);

// ── Required phrase: adjacency vs word-level AND ───────────────────────────────
$rp = $sp->search('+"world war"', $FIELDS);
$rl = $sl->search('+"world war"', $FIELDS);
// positional: only doc1 has world,war adjacent (title AND body). docs 2,3 out.
check('+"world war" positional: only true phrase doc', hitTitles($rp) === ['World War II'], implode('|', hitTitles($rp)));
// non-positional: cross-field AND admits docs 1, 2, 3
check('+"world war" non-positional: cross-field AND admits traps', count($rl['hits']) === 3, 'total=' . $rl['total']);
// phrase_mode escape hatch reproduces word-level semantics on the positional index
$rpl = $sp->search('+"world war"', $FIELDS + ['phrase_mode' => 'unordered']);
check('+"world war" phrase_mode=unordered on positional index', hitTitles($rpl) === hitTitles($rl),
	implode('|', hitTitles($rpl)) . ' vs ' . implode('|', hitTitles($rl)));

// ── Excluded phrase: true exclusion vs AND-in-field over-exclusion ─────────
$ep = $sp->search('war -"world war"', $FIELDS);
$el = $sl->search('war -"world war"', $FIELDS);
// positional: doc1 excluded (real phrase); doc3 SURVIVES (words scattered)
check('-"world war" positional: keeps scattered-words doc', in_array('Geography', hitTitles($ep), true), implode('|', hitTitles($ep)));
check('-"world war" positional: drops true phrase doc', !in_array('World War II', hitTitles($ep), true));
// word-level semantics over-exclude doc3 (both words in body)
check('-"world war" non-positional: over-excludes scattered doc (documented)', !in_array('Geography', hitTitles($el), true), implode('|', hitTitles($el)));

// ── Repeated word in phrase ────────────────────────────────────────────────
$rr = $sp->search('+"new new york"', $FIELDS);
check('+"new new york": repeated word matches doc5 only', hitTitles($rr) === ['New New York'], implode('|', hitTitles($rr)));

// ── Multi-token normalization inside a phrase ──────────────────────────────
$cc = $sp->search('+"coca-cola company"', $FIELDS);
check('+"coca-cola company": normalizes to 3 consecutive tokens', hitTitles($cc) === ['Soft Drinks'], implode('|', hitTitles($cc)));

// ── Single-word "phrase" degrades to a plain required term ─────────────────
$sw = $sp->search('+"war"', $FIELDS);
check('+"war" single-word phrase = required term', $sw['total'] === 4, 'total=' . $sw['total']);

// ── Phrase words stay exact under stemming ─────────────────────────────────
$st = $sp->search('+"world war"', $FIELDS + ['stemming' => true]);
check('+"world war" with stemming: still adjacency-only', hitTitles($st) === ['World War II'], implode('|', hitTitles($st)));

// ── Two-phase retrieval with phrases (heavy body field) ────────────────────
$sp2 = new Searcher($posDb, $PROFILE + ['heavy_fields' => ['body']]);
$tp  = $sp2->search('+"world war"', $FIELDS + ['two_phase' => true]);
check('+"world war" two-phase positional', hitTitles($tp) === ['World War II'], implode('|', hitTitles($tp)));

// ── Non-phrase queries: positional and non-positional indexes rank identically ─────
foreach (['war', 'world war', '+war -cold', 'new york', 'wor*'] as $q) {
	$a = $sp->search($q, $FIELDS);
	$b = $sl->search($q, $FIELDS);
	$sa = array_map(fn($h) => [$h['title'], $h['score']], array_values($a['hits']));
	$sb = array_map(fn($h) => [$h['title'], $h['score']], array_values($b['hits']));
	check("non-phrase parity: '$q'", $sa === $sb && $a['total'] === $b['total'],
		json_encode($sa) . ' vs ' . json_encode($sb));
}

// ── Non-positional index: phrase queries fall back, no crash ──────────────
$lf = $sl->search('+"coca-cola company"', $FIELDS);
check('non-positional index: phrase query safe fallback', $lf['total'] >= 1, 'total=' . $lf['total']);

// ── Builder schema-mismatch guard ──────────────────────────────────────────
$threw = false;
try { new Builder($nonposDb, ['positions' => true]); } catch (InvalidArgumentException $e) { $threw = true; }
check('Builder: positions=true on non-positional index throws', $threw);
$threw = false;
try { new Builder($posDb, ['positions' => false]); } catch (InvalidArgumentException $e) { $threw = true; }
check('Builder: positions=false on positional index throws', $threw);
// reopening with matching/absent config is fine
$reopen = new Builder($posDb);
check('Builder: reopen positional index, auto-detects', $reopen->hasPositions() === true);

// ── Incremental update keeps positions consistent ──────────────────────────
$reopen->addText('9', ['title' => 'Second World War', 'body' => 'the world war of 1939'], 'Second World War');
$reopen->rebuildStats();
$r9 = (new Searcher($posDb, $PROFILE))->search('+"world war"', $FIELDS);
check('incremental doc joins phrase results', in_array('Second World War', hitTitles($r9), true), implode('|', hitTitles($r9)));

// ── Cleanup ────────────────────────────────────────────────────────────────
foreach ([$posDb, $nonposDb] as $f) foreach ([$f, "$f-wal", "$f-shm"] as $g) @unlink($g);
@rmdir($dir);

echo $fails === 0 ? "\nALL OK\n" : "\n$fails FAILURES\n";
exit($fails ? 1 : 0);
