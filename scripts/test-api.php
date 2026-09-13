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

// ── Parser, numbers, exclusion, required words, wildcards ─────────────────
// A second fixture with 130 docs, so the 25% high-frequency cutoff is live
// (it needs 100+): "filler" is in every one of them (a corpus stopword),
// "einstein" in 12, "einstien" in 1, plus words starting with a zero and a
// bare number.
$cutoffDb = "$dir/v37.db";
foreach ([$cutoffDb, "$cutoffDb-wal", "$cutoffDb-shm"] as $g) @unlink($g);
$bc = new Builder($cutoffDb);
for ($i = 1; $i <= 12; $i++) $bc->addText("e$i", ['title' => "Einstein note $i", 'body' => 'einstein filler physics'], "Einstein note $i");
// A body-only word family for the Rarity-on-wildcards check (titles carry no completion,
// so no title-field score or title bonus muddies it): zorbax in 12 docs, zorbal in 4 of
// those, zorbaq in 1. Under a per-completion sum the zorbax+zorbal docs outscore the lone
// zorbaq doc; Rarity credits each doc with its rarest completion, so zorbaq wins there.
for ($i = 1; $i <= 8; $i++) $bc->addText("g$i", ['title' => "Gadget $i", 'body' => 'zorbax filler'], "Gadget $i");
for ($i = 1; $i <= 4; $i++) $bc->addText("w$i", ['title' => "Widget $i", 'body' => 'zorbax zorbal filler'], "Widget $i");
$bc->addText('odd', ['title' => 'Oddity', 'body' => 'zorbaq filler'], 'Oddity');
$bc->addText('typo', ['title' => 'Misspelling', 'body' => 'einstien filler'], 'Misspelling');
$bc->addText('bond', ['title' => 'Agent 007', 'body' => '007 is a filler agent number'], 'Agent 007');
$bc->addText('zero', ['title' => 'Zero', 'body' => '0 is a filler digit and 42 is an answer'], 'Zero');
for ($i = 1; $i <= 115; $i++) $bc->addText("f$i", ['title' => "Page $i", 'body' => 'filler text about nothing'], "Page $i");
$bc->rebuildStats();
$sc = new Searcher($cutoffDb);
$Fc = ['fields' => ['title' => 2.0, 'body' => 1.0], 'algo' => 'bm25'];

// Words starting with "0" (array_filter dropped them) and the bare query "0".
check('word starting with 0 is searched under fuzzy', $sc->search('007', $Fc + ['confidence' => 85])['total'] === 1);
check('bare query "0" is not parsed to nothing', $sc->search('0', $Fc + ['confidence' => 85])['total'] === 1);
// Numbers are exact: no fuzzy variants, and diag says so.
$num = $sc->search('42', $Fc + ['confidence' => 50, 'diagnostics' => true]);
// total 2: the "42 is an answer" body and the filler title "Page 42".
check('numeric word is never fuzzy-expanded',
	$num['total'] === 2 && ($num['diag']['query']['numeric_exact'] ?? null) === ['42']
	&& count(array_filter($num['diag']['expansions'], fn($e) => $e['class'] === 'fuzzy')) === 0, json_encode($num['diag']['expansions']));
// Exclusion never widens the candidate pool: a common excluded word costs nothing and changes nothing.
check('excluding a corpus stopword leaves the rare word\'s hits (none contain it)', $sc->search('einstein -nothing', $Fc)['total'] === 12);
check('excluding a word every doc has empties the result', $sc->search('einstein -filler', $Fc)['total'] === 0);
// All required words common -> sampled, flagged; a rare required word -> not flagged.
$tr = $sc->search('+filler', $Fc + ['diagnostics' => true, 'broad_cap' => 20]);
check('all-common required query is a capped sample and flagged truncated',
	($tr['diag']['candidates']['truncated'] ?? null) === true && $tr['total'] <= 40, "total={$tr['total']}");
$nt = $sc->search('+einstein filler', $Fc + ['diagnostics' => true]);
check('rare required word anchors the intersection (not truncated)',
	($nt['diag']['candidates']['truncated'] ?? null) === false && $nt['total'] === 12, "total={$nt['total']}");
// Wildcards: commonest completion first, per-field cutoff, coverage bit.
$w1 = $sc->search('einst*', $Fc + ['max_wildcard_expansions' => 1, 'diagnostics' => true]);
check('wildcard cap keeps the commonest completion (einstein x12, not einstien x1)',
	$w1['total'] === 12 && ($w1['diag']['wildcards']['einst*']['terms'] ?? []) === ['einstein'], json_encode($w1['diag']['wildcards']));
$w2 = $sc->search('fil*', $Fc + ['diagnostics' => true]);
check('completions above highfreq_cutoff are skipped per field (filler is in 100% of bodies)',
	$w2['total'] === 0, "total={$w2['total']}");
$w3 = $sc->search('einst*', $Fc + ['candidate_limit' => 5, 'diagnostics' => true]);
check('a pure wildcard query prunes like a word (prefix carries a coverage bit)',
	($w3['diag']['candidates']['scored'] ?? null) === 5 && $w3['total'] === 5, json_encode($w3['diag']['candidates']));
$w4 = $sc->search('einst*', $Fc + ['wildcard_row_budget' => 1, 'diagnostics' => true]);
check('wildcard_row_budget stops after the first completion (always keeps one)',
	count($w4['diag']['wildcards']['einst*']['terms'] ?? []) === 1, json_encode($w4['diag']['wildcards']));
check('wildcard_row_budget = 0 disables the budget',
	count($sc->search('einst*', $Fc + ['wildcard_row_budget' => 0, 'diagnostics' => true])['diag']['wildcards']['einst*']['terms'] ?? []) === 2);
// Rarity credits a prefix with the rarest completion a document contains, once.
// (override first: array union keeps the LEFT operand's 'algo', and $Fc already sets bm25)
$ri = $sc->search('zorb*', ['algo' => 'idf', 'per_page' => 3] + $Fc);
$rb = $sc->search('zorb*', ['algo' => 'bm25', 'per_page' => 3] + $Fc);
check('Rarity on a prefix ranks the doc with the rarest completion first (zorbaq x1 beats zorbax+zorbal)',
	(array_values($ri['hits'])[0]['title'] ?? '') === 'Oddity', json_encode(array_map(fn($h) => $h['title'], array_values($ri['hits']))));
check('the per-completion sum still applies to BM25 (two completions beat one rare one)',
	str_starts_with(array_values($rb['hits'])[0]['title'] ?? '', 'Widget'), json_encode(array_map(fn($h) => $h['title'], array_values($rb['hits']))));

// ── Routing knobs, excluded-word synonyms, WordNet lookup words ──────────
check('routing knob rejects an algorithm name that is not an algorithm',
	'' === $e = throwsWith(fn() => new Searcher($idxDb, ['ranking' => ['auto_default_algo' => 'banana']]), 'must be one of'), $e);
check('routing knob rejects auto (would recurse)',
	'' === $e = throwsWith(fn() => new Searcher($idxDb, ['ranking' => ['auto_question_algo' => 'auto']]), 'must be one of'), $e);
$sr = $s->search('einstein', $F + ['algo' => 'auto', 'diagnostics' => true]);
check('auto routes a single typed word to auto_single_word_algo (freq)', ($sr['algo'] ?? '') === 'freq', $sr['algo'] ?? '');
$sr = $s->search('einstein', ['algo' => 'auto', 'fields' => $F['fields'], 'ranking' => ['auto_single_word_algo' => 'dfr']]);
check('auto_single_word_algo knob is honoured', ($sr['algo'] ?? '') === 'dfr', $sr['algo'] ?? '');
$syn = $s->search('einstein -fiend', $F + ['diagnostics' => true, 'synonyms' => [['einstein', 'fiend', 'genius']]]);
$synTerms = array_column(array_filter($syn['diag']['expansions'], fn($e) => $e['class'] === 'synonym'), 'term');
check('an excluded word is never added back as a synonym (genius yes, fiend no)',
	in_array('genius', $synTerms, true) && !in_array('fiend', $synTerms, true), json_encode($synTerms));
require_once __DIR__ . '/../lib/OewnSynonyms.php';
check('queryWords skips the given stopwords and 1-2 letter tokens',
	\MultiSearch\OewnSynonyms::queryWords('what is in the sky at night co-op', ['what', 'is', 'in', 'the', 'at']) === ['sky', 'night']);
check('queryWords still strips operators and numbers',
	\MultiSearch\OewnSynonyms::queryWords('+solar "system" 2020 wind*') === ['solar', 'system', 'wind']);

// ── Compound numbers and one-character stems ──────────────────────────────
check('rootWords never drops a one-character original', Searcher::rootWords(['3']) === ['3'] && Searcher::rootWords(['a']) === ['a']);
$cn = $s->search('3.14 10:30 192.168.0.1 v2.0', $F + ['diagnostics' => true]);
check('compound numbers become phrases of their digit parts; v2.0 stays words',
	($cn['diag']['query']['req_phrases'] ?? null) === [['3', '14'], ['10', '30'], ['192', '168', '0', '1']]
	&& in_array('v2', $cn['diag']['query']['typed_optional'] ?? [], true), json_encode($cn['diag']['query']));
$cw = $s->search('3.14', $F + ['diagnostics' => true, 'compound_numbers' => 'words']);
check('compound_numbers = words keeps the parts as independent numbers',
	($cw['diag']['query']['req_phrases'] ?? null) === [] && ($cw['diag']['query']['typed_optional'] ?? null) === ['3', '14'], json_encode($cw['diag']['query']));

// ── Cleanup ────────────────────────────────────────────────────────────────
foreach ([$idxDb, $junkDb, $bulkDb, $cutoffDb, "$dir/bad.db"] as $f) foreach ([$f, "$f-wal", "$f-shm"] as $g) @unlink($g);
@rmdir($dir);

echo $fails === 0 ? "\nALL OK\n" : "\n$fails FAILURES\n";
exit($fails ? 1 : 0);
