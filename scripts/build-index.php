<?php
/*
scripts/build-index.php — Build data/wikipedia.db from the Simple English
Wikipedia CirrusSearch dump, in one pass.

Run:  php scripts/build-index.php                 (positional index, default)
      php scripts/build-index.php --no-positions  (smaller, no phrase adjacency)
      php scripts/build-index.php --db=path/to/other.db   (build elsewhere —
                 A/B a rebuild against the live index without replacing it)
      php scripts/build-index.php --no-fold       (keep diacritics —
                 café and cafe stay separate terms; see FOLD_DIACRITICS)

This script is a thin driver around Builder::bulkBuild(). An earlier version
carried its own copy of the engine's schema DDL and the IDF/stats SQL — the
bulk strategy (staging tables + sorted insert) was the script's real value,
but the schema and stats were duplicated from lib/MultiBuilder.php with
nothing enforcing agreement, the same private-copy drift that once let the
build-time and query-time tokenizers disagree (see the tokenization contract
in lib/MultiSearch.php). The strategy moved into the library; what's left
here is everything Wikipedia-specific: opening the bz2 dump (with
decompressor fallbacks), walking the NDJSON, choosing page_id as the key,
skipping non-article namespaces and redirects, and the provenance rows.

── What goes into the index ──────────────────────────────────────────────────
  Fields:  title, opening (opening_text, or the first 200 chars of text),
           body (full text). Weights are the Searcher's business, not ours
           (config/corpus-wikipedia.php).
  doc_id:  MediaWiki page_id — stable across rebuilds, so logs and caches
           can be compared build-to-build and results can deep-link to the
           live wiki (…/?curid=<doc_id>).
           History: the first builds used the title STRING as the key, and
           the ranking code parsed it (the corpus-agnostic rewrite removed
           that). Then a sequential integer assigned in dump-stream order —
           the smallest possible varints, but every rebuild renumbered every
           article, which made cross-build persistence impossible. page_id
           keeps integer-key compactness (~1 byte more per posting for 8-digit
           ids) and adds stability. Titles live in documents.title; duplicate
           titles remain supported. Hence doc_id_type INTEGER below.
  Skipped: non-zero namespaces (talk/user/...), pages with empty text
           (redirects), pages without a page_id, and duplicate page_ids
           (bulkBuild dedupes too; a duplicated article must not double its
           postings).

── Build strategy (now in Builder::bulkBuild) ────────────────────────────────
  Stage 1: stream, tokenize (Searcher::tokenize — build and query share ONE
           implementation), append postings to unindexed staging tables.
  Stage 2: INSERT INTO postings_<f> ... SELECT ... ORDER BY term, doc_id — a
           sorted insert fills each B-tree sequentially (no page splits) —
           then stats, drop staging, VACUUM.
  Measured ~10x faster than per-document B-tree inserts on this corpus.
*/

ini_set('memory_limit', '1G');

require_once __DIR__ . '/../lib/MultiSearch.php';
require_once __DIR__ . '/../lib/MultiBuilder.php';

// Token positions (delta-varint blobs on postings rows) are ON by default —
// they enable adjacency-verified quoted phrases at +15.8% index size (measured on the Wikipedia corpus).
// --no-positions builds the non-positional layout (the searcher auto-detects).
$WITH_POSITIONS = !in_array('--no-positions', $argv ?? [], true);

// Fold diacritics at build time (café → cafe, São → sao). A BUILD
// decision: the index stores one vocabulary shape and records it in meta
// (tokenizer_name); the searcher reads that row and folds queries to match,
// so nothing on the query side needs to know. On by default for this
// corpus: Simple English Wikipedia is full of imported names users type
// on an English keyboard, and before folding "sao paulo" / "pokemon" /
// "francois" missed 60–100% of the matching pages. --no-fold to keep the
// old vocabulary. This script is corpus-specific by nature (it parses the
// Wikipedia dump), so build settings live HERE, not in config/.
const FOLD_DIACRITICS = true;
$FOLD = FOLD_DIACRITICS && !in_array('--no-fold', $argv ?? [], true);

define('DATA_DIR', is_dir(__DIR__ . '/../data') ? __DIR__ . '/../data' : __DIR__);
$dumpPath = DATA_DIR . '/simplewiki-latest.json.bz2';
$dbPath   = DATA_DIR . '/wikipedia.db';
// --db=path: build elsewhere (A/B a rebuild against the live index).
foreach ($argv ?? [] as $arg) {
	if (str_starts_with($arg, '--db=')) $dbPath = substr($arg, 5);
}

if (!file_exists($dumpPath)) {
	fwrite(STDERR, "Error: simplewiki-latest.json.bz2 not found.\n");
	fwrite(STDERR, "Run:   php scripts/download-wikipedia.php\n");
	exit(1);
}

// bulkBuild refuses to overwrite; the decision to replace the live index is
// this script's, made explicitly here.
if (file_exists($dbPath)) {
	unlink($dbPath);
	echo "Removed old " . basename($dbPath) . "\n";
}

// ── Open dump (bz2 extension, else an external decompressor) ───────────────

$stream = null;
$proc   = null;
$pipes  = [];

if (function_exists('bzopen')) {
	$stream = bzopen($dumpPath, 'r');
	if (!$stream) {
		fwrite(STDERR, "Error: cannot open dump with bzopen.\n");
		exit(1);
	}
} else {
	$cmds = [
		'bzcat %s',
		'bunzip2 -c %s',
		'7z e -so -tbzip2 %s 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'),
	];
	foreach ($cmds as $cmdTpl) {
		$cmd  = sprintf($cmdTpl, escapeshellarg($dumpPath));
		$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'r'], 2 => ['pipe', 'w']];
		$proc = @proc_open($cmd, $desc, $pipes);
		if (is_resource($proc)) {
			fclose($pipes[0]);
			$test = fread($pipes[1], 64);
			if ($test !== false && strlen($test) > 0) {
				$stream = $pipes[1];
				break;
			}
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($proc);
			$proc = null;
		}
	}
	if (!$stream) {
		fwrite(STDERR, "Error: no bz2 decompressor found.\n");
		exit(1);
	}
}

$readLine = function() use (&$stream, $proc) {
	if ($proc !== null) {
		return fgets($stream, 1048576);
	}
	static $buffer = '';
	while (($pos = strpos($buffer, "\n")) === false) {
		$chunk = bzread($stream, 65536);
		if ($chunk === false || $chunk === '') return false;
		$buffer .= $chunk;
	}
	$line = substr($buffer, 0, $pos);
	$buffer = substr($buffer, $pos + 1);
	return $line;
};

// ── The document stream: everything Wikipedia-specific lives here ──────────

$skipped = 0;   // namespace / redirect / no page_id (bulkBuild counts dupes)

$articles = function () use ($readLine, &$skipped): \Generator {
	$isMetaLine = true;
	while (($line = $readLine()) !== false) {
		$line = trim($line);
		if ($line === '') continue;

		if ($isMetaLine) {         // NDJSON alternates metadata/document lines
			$isMetaLine = false;
			continue;
		}
		$isMetaLine = true;

		$doc = json_decode($line, true);
		if (!$doc || empty($doc['title'])) { $skipped++; continue; }

		// namespace 0 = main article space; skip talk/user/etc.
		// Redirect pages have empty text and are caught by the check below.
		if (($doc['namespace'] ?? 0) !== 0) { $skipped++; continue; }

		$title   = $doc['title'];
		$opening = $doc['opening_text'] ?? '';
		if ($opening === '') {
			$opening = mb_substr($doc['text'] ?? '', 0, 200);
		}
		$text = $doc['text'] ?? '';
		if ($text === '') { $skipped++; continue; }

		$docId = (int)($doc['page_id'] ?? 0);
		if ($docId <= 0) { $skipped++; continue; }

		yield [$docId, ['title' => $title, 'opening' => $opening, 'body' => $text], $title, $opening];
	}
};

// ── Provenance ─────────────────────────────────────────────────────────────
// The index file outlives memory of how it was made; these rows let it answer
// "which source data / when?" on its own (sqlite3 db "SELECT * FROM meta").
// bulkBuild writes schema_version / tokenizer_name / positions / doc_id_type.
$sourceDump = basename($dumpPath) . ' (' . number_format(filesize($dumpPath)) . ' bytes)';
$dumpDateFile = DATA_DIR . '/.dump-date';
if (file_exists($dumpDateFile)) {
	$sourceDump .= ', dump ' . trim(file_get_contents($dumpDateFile));
}

// ── Build ──────────────────────────────────────────────────────────────────

echo "Stage 1: parsing dump (fields: title, opening, body; diacritics " . ($FOLD ? 'FOLDED' : 'kept') . ")...\n";
$t0 = microtime(true);
$stage2Started = null;

$summary = \MultiSearch\Builder::bulkBuild($dbPath, $articles(), [
	'positions'       => $WITH_POSITIONS,
	'fold_diacritics' => $FOLD,
	'doc_id_type'     => 'INTEGER',
	'meta'        => ['builder' => 'scripts/build-index.php', 'source_dump' => $sourceDump],
	'on_progress' => function (string $stage, array $info) use (&$skipped, &$stage2Started) {
		switch ($stage) {
			case 'parse':
				if (!empty($info['done'])) {
					printf("\n  Stage 1 done: %s docs, %s skipped, %.1fs\n\nStage 2: building final tables...\n",
						number_format($info['docs']), number_format($skipped + $info['skipped']), $info['elapsed']);
					$stage2Started = microtime(true);
				} else {
					fprintf(STDERR, "\r  %s docs  |  %ss  |  %s/s  |  %s MB RAM  |  %s skipped",
						number_format($info['docs']), round($info['elapsed'], 1),
						number_format(round($info['docs'] / max($info['elapsed'], 0.1))),
						round(memory_get_usage(true) / 1024 / 1024, 1),
						number_format($skipped + $info['skipped']));
				}
				break;
			case 'postings':
				printf("  postings_%s (sorted insert)... %s rows, %.1fs\n",
					$info['field'], number_format($info['rows']), $info['elapsed']);
				break;
			case 'stats':
				printf("  termstats_%s (doc_freq + idf)... %s terms (N=%s), %.1fs\n",
					$info['field'], number_format($info['terms']), number_format($info['docs']), $info['elapsed']);
				break;
			case 'vacuum':
				printf("  VACUUM (reclaim staging space)... %.1fs\n", $info['elapsed']);
				break;
		}
	},
]);

if ($proc !== null) {
	fclose($stream);
	fclose($pipes[2]);
	proc_close($proc);
} else {
	bzclose($stream);
}

// ── Summary ────────────────────────────────────────────────────────────────

$size = round($summary['bytes'] / 1024 / 1024, 1);
echo "\nDone: {$size} MB, " . round($summary['elapsed'], 1) . "s total"
	. ($stage2Started ? " (stage 2: " . round(microtime(true) - $stage2Started, 1) . "s)" : '') . "\n";
echo "Index ready: $dbPath\n";
