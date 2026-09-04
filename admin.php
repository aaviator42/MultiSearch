<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>MultiSearch — Admin</title>
	<style>
	body { font-family: Verdana, sans-serif; max-width: 50rem; padding: 2rem; margin: auto; font-size: 1rem; }
	code, pre { font-family: monospace; background: #e6e6e6; padding: 0 0.2rem; }
	pre { overflow-x: auto; padding: 0.5rem; }
	table { border-collapse: collapse; width: 100%; margin: 0.5rem 0 1rem; }
	th, td { border: 1px solid #ccc; padding: 0.4rem 0.6rem; text-align: left; vertical-align: top; }
	th { background: #f0f0f0; font-weight: bold; }
	.ok  { color: #2a7a2a; }
	.err { color: #b00; }
	.msg { font-style: italic; color: #333; margin: 0.5rem 0; }
	.cached { font-size: 0.8rem; color: #888; }
	h2 { margin-top: 1.8rem; }
	</style>
	<meta name="robots" content="noindex, nofollow, noarchive">
</head>
<body>

<h3><a href="index.php">← MultiSearch</a> — Admin</h3>
<hr>

<?php

// Paths come from the shared corpus config (see index.php) — they were
// retyped defines here before that file existed.
$CORPUS = require __DIR__ . '/config/corpus-wikipedia.php';
define('DB_PATH',      $CORPUS['db']);
define('OEWN_DB_PATH', $CORPUS['oewn_db']);
define('DATA_DIR',     dirname(DB_PATH));
require __DIR__ . '/lib/MultiSearch.php';   // for Searcher::VERSION only — no index is opened here
define('CACHE_PATH',   DATA_DIR . '/admin-cache.json');

// ── Stats cache ──────────────────────────────────────────────────────────
// The COUNT queries on wikipedia.db (~48M postings) are slow. Cache the
// results in a JSON file and invalidate when the index or the synonym DB
// changes (max mtime of the two).

function dataFingerprint(): string {
	// Fingerprint ONLY the index + synonym DBs. A glob over data/*.db
	// included search_log.db (every logged search invalidated the stats
	// cache) and would include result_cache.db, making invalidation CONSTANT
	// and the cache pointless.
	// Earlier: foreach (glob(DATA_DIR . '/*.db') as $f) { ... }
	$mtimes = [];
	foreach ([DB_PATH, OEWN_DB_PATH] as $f) {
		if (file_exists($f)) $mtimes[] = filemtime($f);
	}
	return empty($mtimes) ? '0' : (string)max($mtimes);
}

function loadCache(): ?array {
	if (!file_exists(CACHE_PATH)) return null;
	$cache = json_decode(file_get_contents(CACHE_PATH), true);
	if (!is_array($cache) || ($cache['fingerprint'] ?? '') !== dataFingerprint()) return null;
	return $cache;
}

function saveCache(array $data): void {
	$data['fingerprint'] = dataFingerprint();
	$data['cached_at'] = date('Y-m-d H:i:s');
	@file_put_contents(CACHE_PATH, json_encode($data, JSON_PRETTY_PRINT));
}

// ── Index stats ───────────────────────────────────────────────────────────
function indexStats(): ?array {
	if (!file_exists(DB_PATH) || filesize(DB_PATH) < 65536) return null;
	// The index has one postings table per field; total postings is the sum
	// across them.
	try {
		$db = new PDO('sqlite:' . DB_PATH, null, null,
			[PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
		$postings = 0;
		// Derive the field list from field_stats instead of hardcoding the
		// Wikipedia field names — the rest of the app is corpus-agnostic and
		// this list was the straggler. Identifier-safe: field names were
		// validated at build time, but guard anyway.
		// Earlier: foreach (['postings_title', 'postings_opening', 'postings_body'] as $tbl) {
		$fieldNames = $db->query("SELECT field FROM field_stats ORDER BY field")->fetchAll(PDO::FETCH_COLUMN);
		foreach ($fieldNames as $f) {
			if (!preg_match('/^[a-z][a-z0-9_]*$/', $f)) continue;
			$postings += (int)$db->query("SELECT COUNT(*) FROM postings_$f")->fetchColumn();
		}
		if ($postings === 0) return null;
		$docs       = (int)$db->query("SELECT COUNT(*) FROM documents")->fetchColumn();
		$terms      = (int)$db->query("SELECT COUNT(*) FROM unique_terms")->fetchColumn();
		$fields     = $db->query("SELECT field, total_docs, total_length FROM field_stats ORDER BY field")->fetchAll(PDO::FETCH_ASSOC);
		$dbSize     = filesize(DB_PATH);
		$dbMtime    = filemtime(DB_PATH);
		// Doc ids are opaque integers — sample display titles instead.
		$samples    = $db->query("SELECT title FROM documents ORDER BY RANDOM() LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
		// Provenance rows (schema/tokenizer/build facts). Every builder writes
		// the meta table.
		$meta = [];
		foreach ($db->query("SELECT key, value FROM meta ORDER BY key") as $r) {
			$meta[$r['key']] = $r['value'];
		}
		return compact('postings', 'docs', 'terms', 'fields', 'dbSize', 'dbMtime', 'samples', 'meta');
	} catch (Exception $e) { return null; }
}

// ── OEWN stats ────────────────────────────────────────────────────────────
function oewnStats(): ?array {
	if (!file_exists(OEWN_DB_PATH) || filesize(OEWN_DB_PATH) < 65536) return null;
	try {
		$db       = new PDO('sqlite:' . OEWN_DB_PATH, null, null,
			[PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
		$synsets  = (int)$db->query("SELECT COUNT(*) FROM synsets")->fetchColumn();
		$mappings = (int)$db->query("SELECT COUNT(*) FROM word_synsets")->fetchColumn();
		$words    = (int)$db->query("SELECT COUNT(DISTINCT word) FROM word_synsets")->fetchColumn();
		$dbSize   = filesize(OEWN_DB_PATH);
		$dbMtime  = filemtime(OEWN_DB_PATH);
		return compact('synsets', 'mappings', 'words', 'dbSize', 'dbMtime');
	} catch (Exception $e) { return null; }
}

// ── Load stats (from cache or fresh) ─────────────────────────────────────
$fromCache = false;
$forceRefresh = isset($_GET['refresh']);
$cache = $forceRefresh ? null : loadCache();

if ($cache) {
	$idxStats  = $cache['idx'] ?? null;
	$oewn      = $cache['oewn'] ?? null;
	$fromCache = true;
	$cachedAt  = $cache['cached_at'] ?? '';
} else {
	$idxStats = indexStats();
	$oewn     = oewnStats();
	saveCache(['idx' => $idxStats, 'oewn' => $oewn]);
}

?>

<!-- ── Build instructions ─────────────────────────────────────────────── -->
<h2>Build / Rebuild Index</h2>
<p>The index is built from the command line:</p>
<pre>php scripts/download-wikipedia.php   # download the latest dump (--insecure: skip TLS verification)
php scripts/build-index.php          # parse dump → wikipedia.db (production index)
php scripts/download-oewn.php        # optional: refresh the OEWN zip (the repo ships it + oewn.db)
php scripts/build-oewn.php           # optional: rebuild the synonym DB from the zip
                                     #   build-index flags: --no-positions (smaller, no phrase adjacency),
                                     #   --no-fold (keep diacritics), --db=path (build elsewhere for A/B)</pre>

<!-- ── Index stats ────────────────────────────────────────────────────────-->
<h2>Search Index <small style="font-weight:normal;">(data/wikipedia.db)</small></h2>
<?php if ($idxStats): ?>
<p>
	Engine: <b>MultiSearch v<?= htmlspecialchars(\MultiSearch\Searcher::VERSION) ?></b> &nbsp;|&nbsp;
	Built: <b><?= date('Y-m-d H:i:s', $idxStats['dbMtime']) ?></b> &nbsp;|&nbsp;
	Size: <b><?= round($idxStats['dbSize'] / 1024 / 1024, 1) ?> MB</b> &nbsp;|&nbsp;
	Articles: <b><?= number_format($idxStats['docs']) ?></b> &nbsp;|&nbsp;
	Unique terms: <b><?= number_format($idxStats['terms']) ?></b> &nbsp;|&nbsp;
	Postings: <b><?= number_format($idxStats['postings']) ?></b>
</p>
<table>
	<tr><th>Field</th><th>Docs</th><th>Total tokens</th><th>Avg tokens/doc</th></tr>
	<?php foreach ($idxStats['fields'] as $f): ?>
	<tr>
		<td><code><?= htmlspecialchars($f['field']) ?></code></td>
		<td><?= number_format($f['total_docs']) ?></td>
		<td><?= number_format($f['total_length']) ?></td>
		<td><?= $f['total_docs'] > 0 ? number_format(round($f['total_length'] / $f['total_docs'])) : '—' ?></td>
	</tr>
	<?php endforeach; ?>
</table>

<?php if (!empty($idxStats['meta'])): ?>
<!-- Index provenance — what the file says about its own origins -->
<p><b>Index provenance</b> <small>(meta table)</small></p>
<table>
	<?php foreach ($idxStats['meta'] as $k => $v): ?>
	<tr><td style="width:28%;"><code><?= htmlspecialchars($k) ?></code></td><td><?= htmlspecialchars($v) ?></td></tr>
	<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if (!empty($idxStats['samples'])): ?>
<p><b>Sample articles:</b></p>
<ul>
<?php foreach ($idxStats['samples'] as $s): ?>
	<li><a href="https://simple.wikipedia.org/wiki/<?= rawurlencode(str_replace(' ', '_', $s)) ?>" target="_blank"><?= htmlspecialchars($s) ?></a></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<?php else: ?>
<p class="err">wikipedia.db not found. Run the build pipeline above.</p>
<?php endif; ?>

<!-- ── OEWN ──────────────────────────────────────────────────────────────-->
<h2>OEWN Synonym DB <small style="font-weight:normal;">(data/oewn.db)</small></h2>
<?php if ($oewn): ?>
<p>
	Built: <b><?= date('Y-m-d H:i:s', $oewn['dbMtime']) ?></b> &nbsp;|&nbsp;
	Size: <b><?= round($oewn['dbSize'] / 1024 / 1024, 1) ?> MB</b> &nbsp;|&nbsp;
	Synsets: <b><?= number_format($oewn['synsets']) ?></b> &nbsp;|&nbsp;
	Words: <b><?= number_format($oewn['words']) ?></b> &nbsp;|&nbsp;
	Mappings: <b><?= number_format($oewn['mappings']) ?></b>
</p>
<?php else: ?>
<p class="err">oewn.db not found. Run <code>php scripts/build-oewn.php</code>.</p>
<?php endif; ?>

<br><hr>
<small>
	<?php if ($fromCache): ?>
		<span class="cached">Stats cached at <?= htmlspecialchars($cachedAt) ?> — <a href="admin.php?refresh">refresh</a></span> &nbsp;|&nbsp;
	<?php endif; ?>
	<a href="index.php">← Back to search</a> &nbsp;|&nbsp;
	<a href="logs.php">Search logs</a>
</small>

</body>
</html>
