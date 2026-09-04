<?php
/*
scripts/test-api.php — API contract tests: the error philosophy and the
diagnostics channel, plus Builder::bulkBuild checked against the incremental
build. Fixture-based (tiny throwaway Builder indexes), independent of
data/wikipedia.db.

The contract under test (see the error-contract notes in lib/MultiSearch.php):
  - Configuration errors THROW with actionable messages at the earliest
    possible moment (bad path, non-index DB, unknown algo, unknown field,
    typo'd ranking knob — the last was already loud; the rest used to fail
    as obscure PDO errors or, worst, silently empty results).
  - Valid queries never throw; empty results are never an error signal.
  - 'diagnostics' => true adds a non-contractual 'diag' key; the default
    result shape is unchanged and byte-identical.

Run:  php scripts/test-api.php
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
function throwsWith(callable $fn, string $needle): string {
	try { $fn(); } catch (InvalidArgumentException $e) {
		return str_contains($e->getMessage(), $needle) ? '' : 'wrong message: ' . $e->getMessage();
	} catch (Throwable $e) {
		return 'wrong exception: ' . get_class($e) . ': ' . $e->getMessage();
	}
	return 'did not throw';
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'msearch-apitest-' . getmypid();
@mkdir($dir);
$idxDb = "$dir/api.db";
$junkDb = "$dir/junk.db";
foreach ([$idxDb, $junkDb] as $f) foreach ([$f, "$f-wal", "$f-shm"] as $g) @unlink($g);

// ── Fixture: 12 docs with 'einstein', 1 with the typo 'einstien' — enough
// df spread (12 >= 10 x 1) to trip the typo-swap noise filter.
$b = new Builder($idxDb);
for ($i = 1; $i <= 12; $i++) {
	$b->addText("d$i", ['title' => "Einstein paper $i", 'body' => 'einstein wrote about relativity and gravity'], "Einstein paper $i");
}
$b->addText('typo', ['title' => 'Misspelled page', 'body' => 'einstien is a common misspelling here'], 'Misspelled page');
$b->addText('wolf', ['title' => 'Wolves', 'body' => 'wolves running in packs'], 'Wolves');
$b->rebuildStats();

// ── Error contract ─────────────────────────────────────────────────────────
check('bad path throws (no silent empty-file creation)',
	'' === $e = throwsWith(fn() => new Searcher("$dir/nope.db"), 'not found'), $e);
check('bad path did not create the file', !file_exists("$dir/nope.db"));

(new PDO("sqlite:$junkDb"))->exec("CREATE TABLE t (x)");   // a real SQLite file, not an index
check('non-index DB throws',
	'' === $e = throwsWith(fn() => new Searcher($junkDb), 'not a MultiSearch index'), $e);

$s = new Searcher($idxDb, ['title_field' => 'title']);
$F = ['fields' => ['title' => 2.0, 'body' => 1.0]];

check('unknown algo throws with valid list',
	'' === $e = throwsWith(fn() => $s->search('einstein', $F + ['algo' => 'banana']), "Unknown algo 'banana'"), $e);
check('unknown field throws with index roster',
	'' === $e = throwsWith(fn() => $s->search('einstein', ['fields' => ['nope' => 1.0]]), 'Unknown field'), $e);
check('ranking-knob typo throws (unknown key is never a silent no-op)',
	'' === $e = throwsWith(fn() => $s->search('einstein', $F + ['ranking' => ['k9' => 1]]), 'Unknown ranking keys'), $e);
check('unknown algo throws with the key named',
	'' === $e = throwsWith(fn() => $s->search('einstein', $F + ['algo' => 'nosuchalgo']), "Unknown algo 'nosuchalgo'"), $e);
check('nonsense query: empty result, NO exception',
	$s->search('xyzzyplugh', $F)['total'] === 0);

// ── Diagnostics channel ────────────────────────────────────────────────────
$plain = $s->search('einstein', $F);
check('diag ABSENT by default', !array_key_exists('diag', $plain));

$r = $s->search('einstien wolves', $F + [
	'algo' => 'auto', 'confidence' => 85, 'stemming' => true, 'diagnostics' => true,
]);
$d = $r['diag'] ?? null;
check('diag present when requested', is_array($d));
check('diag: requested vs resolved algo',
	($d['algo']['requested'] ?? '') === 'auto' && in_array($d['algo']['resolved'] ?? '', Searcher::ALGOS, true),
	json_encode($d['algo'] ?? null));
$classes = array_unique(array_column($d['expansions'] ?? [], 'class'));
check('diag: stem expansions recorded (wolves -> wolf)', in_array('stem', $classes, true), implode(',', $classes));
check('diag: fuzzy expansions recorded', in_array('fuzzy', $classes, true), implode(',', $classes));
check('diag: typo swap recorded (einstien -> einstein)',
	($d['typo_swaps'][0]['typed'] ?? '') === 'einstien' && ($d['typo_swaps'][0]['promoted'] ?? '') === 'einstein',
	json_encode($d['typo_swaps'] ?? null));
check('diag: auto saw the typo evidence', ($d['algo']['typo_evidence'] ?? null) === true);
check('diag: candidate funnel present',
	isset($d['candidates']['matched'], $d['candidates']['scored'], $d['candidates']['passed_filters']));
check('diag: typed words listed', ($d['query']['typed_optional'] ?? []) === ['einstien', 'wolves'],
	json_encode($d['query']['typed_optional'] ?? null));

$w = $s->search('eins* -running', $F + ['diagnostics' => true]);
check('diag: wildcard expansion summarized',
	($w['diag']['wildcards']['eins*']['count'] ?? 0) >= 2, json_encode($w['diag']['wildcards'] ?? null));

// ── Result-shape stability with diagnostics on ─────────────────────────────
$off = $s->search('einstein relativity', $F);
$on  = $s->search('einstein relativity', $F + ['diagnostics' => true]);
unset($on['diag']);
check('diagnostics=true changes NOTHING except adding diag', $off === $on);

// ── Builder::bulkBuild — same index, built the fast way ───────────────────
$bulkDb = "$dir/bulk.db";
@unlink($bulkDb);
$docs = function (): Generator {
	for ($i = 1; $i <= 12; $i++) {
		yield [$i, ['title' => "Einstein paper $i", 'body' => 'einstein wrote about relativity and gravity'], "Einstein paper $i"];
	}
	yield [13, ['title' => 'Misspelled page', 'body' => 'einstien is a common misspelling here'], 'Misspelled page'];
	yield [14, ['title' => 'Wolves', 'body' => ['wolves', 'running', 'in', 'packs']], 'Wolves'];   // pre-tokenized field
	yield [14, ['title' => 'Duplicate id', 'body' => 'must be skipped'], 'Dup'];                   // duplicate: first wins
};
$stages = [];
$sum = Builder::bulkBuild($bulkDb, $docs(), [
	'doc_id_type' => 'INTEGER',
	'meta'        => ['source_dump' => 'fixture'],
	'on_progress' => function (string $stage) use (&$stages) { $stages[$stage] = true; },
]);
check('bulkBuild: 14 docs, 1 duplicate skipped', $sum['docs'] === 14 && $sum['skipped'] === 1, json_encode([$sum['docs'], $sum['skipped']]));
check('bulkBuild: per-field summary', ($sum['fields']['body']['postings'] ?? 0) > 0 && ($sum['fields']['title']['terms'] ?? 0) > 0);
check('bulkBuild: progress stages reported', isset($stages['parse'], $stages['postings'], $stages['stats'], $stages['vacuum']), implode(',', array_keys($stages)));
check('bulkBuild: refuses to overwrite',
	'' === $e = throwsWith(fn() => Builder::bulkBuild($bulkDb, $docs()), 'refuses to overwrite'), $e);

$sb = new Searcher($bulkDb, ['title_field' => 'title']);
$bulkHit = $sb->search('wolves', $F);
check('bulkBuild: searchable, title returned', ($bulkHit['hits'][14]['title'] ?? '') === 'Wolves', json_encode(array_keys($bulkHit['hits'])));
check('bulkBuild: integer doc ids', array_keys($bulkHit['hits']) === [14]);
check('bulkBuild: pre-tokenized field got positions (phrase matches)', $sb->search('"running in packs"', $F)['total'] === 1);
$inc = $s->search('einstien', $F + ['confidence' => 85, 'diagnostics' => true]);
$blk = $sb->search('einstien', $F + ['confidence' => 85, 'diagnostics' => true]);
check('bulkBuild: typo swap + scores identical to the incremental build',
	array_map(fn($h) => round($h['score'], 6), array_values($inc['hits']))
		=== array_map(fn($h) => round($h['score'], 6), array_values($blk['hits']))
	&& ($blk['diag']['typo_swaps'][0]['promoted'] ?? '') === 'einstein');
$bulkMeta = (new PDO("sqlite:$bulkDb"))->query("SELECT key, value FROM meta")->fetchAll(PDO::FETCH_KEY_PAIR);
check('bulkBuild: provenance rows', ($bulkMeta['doc_id_type'] ?? '') === 'INTEGER' && ($bulkMeta['source_dump'] ?? '') === 'fixture'
	&& ($bulkMeta['tokenizer_name'] ?? '') === Searcher::TOKENIZER_DEFAULT, json_encode($bulkMeta));
$reopened = new Builder($bulkDb);   // incremental API on a bulk-built index
check('bulkBuild: reopened Builder detects INTEGER ids + positions', $reopened->docIdType() === 'INTEGER' && $reopened->hasPositions());
check('Builder: bad doc_id_type throws',
	'' === $e = throwsWith(fn() => new Builder("$dir/bad.db", ['doc_id_type' => 'UUID']), "doc_id_type must be"), $e);

// ── Cleanup ────────────────────────────────────────────────────────────────
foreach ([$idxDb, $junkDb, $bulkDb, "$dir/bad.db"] as $f) foreach ([$f, "$f-wal", "$f-shm"] as $g) @unlink($g);
@rmdir($dir);

echo $fails === 0 ? "\nALL OK\n" : "\n$fails FAILURES\n";
exit($fails ? 1 : 0);
