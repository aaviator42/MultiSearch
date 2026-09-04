<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>MultiSearch — Search Logs</title>
	<style>
	body { font-family: Verdana, sans-serif; max-width: 64rem; padding: 2rem; margin: auto; font-size: 0.95rem; }
	code { font-family: monospace; background: #e6e6e6; padding: 0 0.2rem; }
	table { border-collapse: collapse; width: 100%; margin: 0.5rem 0 1rem; }
	th, td { border: 1px solid #ccc; padding: 0.35rem 0.5rem; text-align: left; vertical-align: top; }
	th { background: #f0f0f0; font-weight: bold; font-size: 0.85rem; }
	td { font-size: 0.85rem; }
	.zero { color: #b00; font-weight: bold; }
	.slow { color: #b00; }
	.filter { margin: 0.5rem 0 1rem; }
	.filter a { margin-right: 1rem; font-family: monospace; }
	.pagination { margin: 0.8rem 0; font-size: 0.9rem; }
	.pagination a { margin: 0 0.3rem; font-family: monospace; }
	h2 { margin-top: 1.8rem; }
	.stat-grid { display: flex; gap: 2rem; flex-wrap: wrap; }
	.stat-box { flex: 1; min-width: 14rem; }
	.stat-box table { font-size: 0.8rem; }
	/* Expandable top-results in the recent-searches table. */
	details.top { font-size: 0.8rem; }
	details.top summary { cursor: pointer; color: #06c; white-space: nowrap; }
	details.top ol { margin: 0.2rem 0 0.2rem 1.2rem; padding: 0; }
	details.top li { margin: 0; }
	.dim { color: #888; }
	</style>
	<meta name="robots" content="noindex, nofollow, noarchive">
</head>
<body>

<h3>MultiSearch — Search Logs</h3>
<hr>

<?php

$dbPath = __DIR__ . '/data/search_log.db';

if (!file_exists($dbPath)) {
	echo '<p>No searches logged yet.</p></body></html>';
	exit;
}

$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA busy_timeout = 2000");   // don't 500 during a WAL checkpoint

// ── Filter ────────────────────────────────────────────────────────────────
// Validate the corpus parameter ONCE, up front, so every later use — the
// SQL filter AND the pagination links at the bottom of the page — only ever
// sees a whitelisted value. Earlier the in_array() check guarded only the
// SQL path; the raw $_GET value survived in $corpus and was echoed unescaped
// into the pagination hrefs — reflected XSS via ?corpus="><script>. Classic
// validated-under-one-condition, used-under-another.
//
// The whitelist is DATA-DRIVEN — the distinct corpus names actually in the
// log (app-written values from each corpus config's 'name', never user
// input). A hand-maintained array (earlier: ['wikipedia']) could drift from
// what adapters log; this can't. The filter UI below renders only when logs
// from MORE THAN ONE corpus share the DB — single-corpus deployments (the
// normal case) see no filter at all, multi-corpus adapters get a working one
// with their own names, no code changes.
$corpora = $db->query("SELECT DISTINCT corpus FROM search_log WHERE corpus != '' ORDER BY corpus")
              ->fetchAll(PDO::FETCH_COLUMN);
$multiCorpus = count($corpora) > 1;

$corpus = $_GET['corpus'] ?? '';
if (!in_array($corpus, $corpora, true)) $corpus = '';
$where  = '';
$params = [];
if ($corpus !== '') {
	$where  = "WHERE corpus = ?";
	$params = [$corpus];
}

// ── Summary stats ─────────────────────────────────────────────────────────
// A broken statement used to sit here, abandoned mid-edit. Operator
// precedence made it evaluate the ternary on the (int) cast of execute()'s
// bool, and with a filter active the inner query() ran the WHERE with an
// UNBOUND placeholder. Its result was overwritten by the proper query
// directly below, so it was pure wasted work (one extra COUNT scan per page
// view) — kept here as a reminder to finish or delete a half-edited
// statement rather than leave it running beside its replacement.
// Earlier, broken:
// $totalSearches = (int)$db->prepare("SELECT COUNT(*) FROM search_log $where")
// 	->execute($params) ? $db->query("SELECT COUNT(*) FROM search_log " . ($where ?: ''))->fetchColumn() : 0;
// // Re-query properly with params
$stmt = $db->prepare("SELECT COUNT(*) FROM search_log $where");
$stmt->execute($params);
$totalSearches = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM search_log $where" . ($where ? " AND total = 0" : " WHERE total = 0"));
$stmt->execute($params);
$zeroCount = (int)$stmt->fetchColumn();

// Cached rows measure a cache fetch, not a search — exclude them from every
// latency average, and surface the hit rate itself.
$stmt = $db->prepare("SELECT ROUND(AVG(CASE WHEN cached = 0 THEN elapsed END), 4),
	SUM(cached) FROM search_log $where");
$stmt->execute($params);
[$avgElapsed, $cachedCount] = $stmt->fetch(PDO::FETCH_NUM) + [0, 0];
$avgElapsed  = $avgElapsed ?: 0;
$cachedCount = (int)$cachedCount;

// This page assumes the current log schema: a search_log.db written by an
// older schema fatals on the enriched queries below — delete the file and
// index.php recreates it on the next search (the log is a disposable
// diagnostic artifact; there is no migration by design).

// Helper: compose "AND ..." onto the (possibly empty) corpus filter.
$andWhere = fn(string $cond): string => $where !== '' ? "$where AND $cond" : "WHERE $cond";

// Elapsed segmented by retrieval mode — two_phase and exhaustive change
// what elapsed/total MEAN, so a single average mixes incomparables.
$sql = "SELECT
	ROUND(AVG(CASE WHEN cached=0 AND COALESCE(two_phase,0)=0 AND COALESCE(exhaustive,0)=0 THEN elapsed END), 4) AS std,
	ROUND(AVG(CASE WHEN cached=0 AND two_phase=1  THEN elapsed END), 4) AS twop,
	ROUND(AVG(CASE WHEN cached=0 AND exhaustive=1 THEN elapsed END), 4) AS exh
	FROM search_log $where";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$modeAvgs = $stmt->fetch(PDO::FETCH_ASSOC);

?>

<?php if ($multiCorpus): ?>
<div class="filter">
	Filter:
	<a href="logs.php"<?= $corpus === '' ? ' style="font-weight:bold;"' : '' ?>>All</a>
	<?php foreach ($corpora as $c): ?>
	<a href="logs.php?corpus=<?= urlencode($c) ?>"<?= $corpus === $c ? ' style="font-weight:bold;"' : '' ?>><?= htmlspecialchars($c) ?></a>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<p>
	Total searches: <b><?= number_format($totalSearches) ?></b> &nbsp;|&nbsp;
	Zero-result: <b class="<?= $zeroCount > 0 ? 'zero' : '' ?>"><?= number_format($zeroCount) ?></b>
		(<?= $totalSearches > 0 ? round($zeroCount / $totalSearches * 100, 1) : 0 ?>%) &nbsp;|&nbsp;
	Avg time: <b><?= number_format($avgElapsed, 4) ?>s</b> <span class="dim">(uncached)</span> &nbsp;|&nbsp;
	Cache hits: <b><?= number_format($cachedCount) ?></b>
		(<?= $totalSearches > 0 ? round($cachedCount / $totalSearches * 100, 1) : 0 ?>%)
	<?php if ($modeAvgs): ?>
	<span class="dim">(standard <?= $modeAvgs['std'] ?? '—' ?>s
		· two-phase <?= $modeAvgs['twop'] ?? '—' ?>s
		· exhaustive <?= $modeAvgs['exh'] ?? '—' ?>s)</span>
	<?php endif; ?>
</p>

<div class="stat-grid">
<div class="stat-box">
<h4>Resolved algorithm distribution</h4>
<?php
// What actually ran: 'algo' mostly says "auto" (the UI default) —
// algo_resolved is auto's pick, so this table is auto's live routing
// distribution.
$sql = "SELECT algo || CASE WHEN algo_resolved IS NOT NULL AND algo_resolved != algo
			THEN ' → ' || algo_resolved ELSE '' END AS route,
		COUNT(*) AS cnt, ROUND(AVG(elapsed), 4) AS avg_t
	FROM search_log " . $andWhere("algo_resolved IS NOT NULL") . "
	GROUP BY route ORDER BY cnt DESC LIMIT 12";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($rows): ?>
<table>
	<tr><th>Route</th><th>#</th><th>Avg time</th></tr>
	<?php foreach ($rows as $r): ?>
	<tr><td><?= htmlspecialchars(strtoupper($r['route'])) ?></td><td><?= $r['cnt'] ?></td><td><?= $r['avg_t'] ?>s</td></tr>
	<?php endforeach; ?>
</table>
<?php else: ?>
<p><small>No enriched rows yet.</small></p>
<?php endif; ?>
</div>

<div class="stat-box">
<h4>By index build</h4>
<?php
// Rankings and totals shift across rebuilds; rows are only comparable
// within a build generation (index_built is the index's meta.built_at plus
// a +pos flag for positional builds, so the two layouts never mix).
$sql = "SELECT COALESCE(index_built, '—') AS build,
		COALESCE(engine, '—') AS engine,
		COUNT(*) AS cnt, ROUND(AVG(elapsed), 4) AS avg_t
	FROM search_log $where
	GROUP BY build, engine ORDER BY build DESC LIMIT 8";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($rows): ?>
<table>
	<tr><th>Index built</th><th>Engine</th><th>#</th><th>Avg time</th></tr>
	<?php foreach ($rows as $r): ?>
	<tr>
		<td style="white-space:nowrap;"><?= htmlspecialchars(substr($r['build'], 0, 19)) . (str_contains($r['build'], '+pos') ? ' +pos' : '') ?></td>
		<td><?= htmlspecialchars($r['engine']) ?></td>
		<td><?= $r['cnt'] ?></td>
		<td><?= $r['avg_t'] ?>s</td>
	</tr>
	<?php endforeach; ?>
</table>
<?php else: ?>
<p><small>No rows yet.</small></p>
<?php endif; ?>
</div>
</div>

<!-- ── Problem areas ──────────────────────────────────────────────────── -->
<div class="stat-grid">

<div class="stat-box">
<h4>Zero-result queries</h4>
<?php
// Page 1 only — pagination re-logs the same query per page view, which
// inflated these counts with pagination depth.
$sql = "SELECT query, COUNT(*) AS cnt FROM search_log " .
	$andWhere("total = 0 AND page = 1") .
	" GROUP BY query ORDER BY cnt DESC LIMIT 15";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($rows): ?>
<table>
	<tr><th>Query</th><th>#</th></tr>
	<?php foreach ($rows as $r): ?>
	<tr><td class="zero"><?= htmlspecialchars($r['query']) ?></td><td><?= $r['cnt'] ?></td></tr>
	<?php endforeach; ?>
</table>
<?php else: ?>
<p><small>None yet.</small></p>
<?php endif; ?>
</div>

<div class="stat-box">
<h4>Slowest queries</h4>
<?php
$sql = "SELECT query, corpus, elapsed, total FROM search_log " . $andWhere("cached = 0") . " ORDER BY elapsed DESC LIMIT 15";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($rows): ?>
<table>
	<tr><th>Query</th><?= $multiCorpus ? '<th>Corpus</th>' : '' ?><th>Time</th><th>Hits</th></tr>
	<?php foreach ($rows as $r): ?>
	<tr>
		<td><?= htmlspecialchars($r['query']) ?></td>
		<?php if ($multiCorpus): ?><td><?= htmlspecialchars($r['corpus']) ?></td><?php endif; ?>
		<td class="<?= $r['elapsed'] > 1.0 ? 'slow' : '' ?>"><?= number_format($r['elapsed'], 4) ?>s</td>
		<td><?= $r['total'] ?></td>
	</tr>
	<?php endforeach; ?>
</table>
<?php else: ?>
<p><small>None yet.</small></p>
<?php endif; ?>
</div>

<div class="stat-box">
<h4>Most common queries</h4>
<?php
// Page 1 only — count queries, not pagination clicks.
$sql = "SELECT query, COUNT(*) AS cnt, ROUND(AVG(total),1) AS avg_hits FROM search_log "
	. $andWhere("page = 1") . " GROUP BY query ORDER BY cnt DESC LIMIT 15";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($rows): ?>
<table>
	<tr><th>Query</th><th>#</th><th>Avg hits</th></tr>
	<?php foreach ($rows as $r): ?>
	<tr>
		<td><?= htmlspecialchars($r['query']) ?></td>
		<td><?= $r['cnt'] ?></td>
		<td><?= $r['avg_hits'] ?></td>
	</tr>
	<?php endforeach; ?>
</table>
<?php else: ?>
<p><small>None yet.</small></p>
<?php endif; ?>
</div>

</div>

<!-- ── Recent searches ────────────────────────────────────────────────── -->
<h2>Recent searches</h2>

<?php
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Reuse the summary count — this identical filtered COUNT already ran at
// the top of the page. Before the cleanup it executed THREE times per
// request (once in the broken statement, once for the summary, once here).
$totalRows = $totalSearches;
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$sql = "SELECT * FROM search_log $where ORDER BY id DESC LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php if ($rows): ?>
<table>
	<tr>
		<th>Time</th>
		<th>Query</th>
		<th>Algo</th>
		<th>Conf</th>
		<th>Options</th>
		<th>Hits</th>
		<th>Top results</th>
		<th>Pg</th>
		<th>Time(s)</th>
		<th>Mem</th>
		<th>IP</th>
	</tr>
	<?php foreach ($rows as $r): ?>
	<?php
		// "AUTO → COVER" when auto resolved to something else.
		$algoCell = strtoupper($r['algo']);
		if ($r['algo_resolved'] !== $r['algo']) {
			$algoCell .= ' → ' . strtoupper($r['algo_resolved']);
		}
		// Compact option flags: the settings that change what the numbers mean.
		$opts = [];
		if ($r['stemming'])                 $opts[] = 'stem';
		if ($r['stopwords'])                $opts[] = 'stop';
		if ($r['synonyms'])                 $opts[] = 'oewn' . (isset($r['oewn_senses']) ? $r['oewn_senses'] : '');
		if (!empty($r['two_phase']))        $opts[] = '2P';
		if (!empty($r['exhaustive']))       $opts[] = 'EXH';
		if (!empty($r['cached']))           $opts[] = 'CACHED';
		$topResults = json_decode($r['top_results'] ?? '', true);
		$expandT = (float)($r['elapsed_expand'] ?? 0);
	?>
	<tr>
		<td style="white-space:nowrap;"><?= htmlspecialchars(substr($r['ts'], 0, 19)) ?></td>
		<td><?= htmlspecialchars($r['query']) ?></td>
		<td style="white-space:nowrap;"><?= htmlspecialchars($algoCell) ?></td>
		<td><?= $r['confidence'] ?></td>
		<td><?= htmlspecialchars(implode(' ', $opts)) ?></td>
		<td class="<?= $r['total'] == 0 ? 'zero' : '' ?>"><?= $r['total'] ?></td>
		<td>
		<?php if (is_array($topResults) && !empty($topResults)): ?>
			<details class="top">
				<summary><?= htmlspecialchars($topResults[0]['t'] ?? '?') ?> …</summary>
				<ol>
				<?php foreach ($topResults as $tr): ?>
					<li><?= htmlspecialchars($tr['t'] ?? '?') ?>
						<span class="dim">(<?= round((float)($tr['s'] ?? 0), 1) ?> · #<?= htmlspecialchars((string)($tr['d'] ?? '?')) ?>)</span></li>
				<?php endforeach; ?>
				</ol>
			</details>
		<?php else: ?>
			<span class="dim">—</span>
		<?php endif; ?>
		</td>
		<td><?= $r['page'] ?></td>
		<td class="<?= $r['elapsed'] > 1.0 ? 'slow' : '' ?>"><?= number_format($r['elapsed'], 4)
			?><?= $expandT > 0 ? '<span class="dim">+' . number_format($expandT, 3) . '</span>' : '' ?></td>
		<td class="dim"><?= isset($r['mem_kb']) && $r['mem_kb'] !== null ? round($r['mem_kb'] / 1024) . 'M' : '—' ?></td>
		<td><?= htmlspecialchars($r['ip'] ?? '') ?></td>
	</tr>
	<?php endforeach; ?>
</table>

<?php if ($totalPages > 1): ?>
<div class="pagination">
	Page <?= $page ?>/<?= $totalPages ?>
	<?php
	// $corpus is whitelist-normalized at the top of the file, so this
	// interpolation only ever sees '' or a corpus name the app itself logged;
	// urlencode is belt and braces for names with special characters.
	$qs = $corpus !== '' ? '&corpus=' . urlencode($corpus) : '';
	if ($page > 1): ?>
		<a href="logs.php?page=<?= $page - 1 ?><?= $qs ?>">← Prev</a>
	<?php endif; ?>
	<?php if ($page < $totalPages): ?>
		<a href="logs.php?page=<?= $page + 1 ?><?= $qs ?>">Next →</a>
	<?php endif; ?>
</div>
<?php endif; ?>

<?php else: ?>
<p>No searches logged yet.</p>
<?php endif; ?>

<br><hr>
<small>
	<a href="index.php">Search</a> &nbsp;|&nbsp;
	<a href="admin.php">Admin</a>
</small>

</body>
</html>
