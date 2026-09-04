<?php
/*
scripts/download-wikipedia.php — Download the latest Simple English Wikipedia
CirrusSearch dump into data/.

Discovers the most recent dump date from Wikimedia, then downloads the
simplewiki_content file as data/simplewiki-latest.json.bz2. Skips the
download when data/.dump-date says that date is already present.

Run:  php scripts/download-wikipedia.php
      php scripts/download-wikipedia.php --insecure   (skip TLS verification)

── Data source ───────────────────────────────────────────────────────────────
  dumps.wikimedia.org/other/cirrus_search_index/{date}/index_name=simplewiki_content/
  Format: NDJSON pairs (metadata line + document line), bzip2 compressed.
  ~524 MB compressed, ~283K articles after filtering redirects.

── History ───────────────────────────────────────────────────────────────────
  - Originally used the /other/cirrussearch/ endpoint (deprecated late 2025).
    Old format: simplewiki-YYYYMMDD-cirrussearch-content.json.gz
    New format: simplewiki_content-YYYYMMDD-00000.json.bz2
    Same NDJSON structure inside, just different compression and URL layout.

  - Tried downloading the XML article dump (pages-articles.xml.bz2) first,
    but MediaWiki markup parsing was too complex. CirrusSearch dumps have
    pre-extracted plain text, which is exactly what we need.

  - The OEWN download used to live in this script too. It is now
    download-oewn.php, so this file fetches exactly what its name says.
*/

// ── Download helpers ─────────────────────────────────────────────────────
// This block is the same in download-wikipedia.php and download-oewn.php on
// purpose: each script is self-contained and readable on its own, and two
// short scripts don't justify a shared include. If you change it, change
// both.
//
// Why the curl extension:
//   - PHP's file_get_contents() / get_headers() fail on Windows due to missing
//     CA bundles. The curl extension handles SSL natively.
//   - Tried shelling out to curl via exec(), but Windows PHP's exec() doesn't
//     reliably capture output. The extension works cross-platform.
//
// TLS policy: certificate verification is ON. An earlier version disabled it
// entirely (VERIFYPEER/VERIFYHOST false) as a workaround for Windows PHP's
// missing CA bundles — which silenced the error by accepting ANY certificate
// on every download. Now: verify, using php.ini's curl.cainfo when
// configured, else a cacert.pem placed next to this script or in data/
// (download one from https://curl.se/docs/caextract.html). Explicit opt-out:
// --insecure flag, with a loud warning — an informed choice instead of a
// silent default.
// Earlier (both helpers):
// CURLOPT_SSL_VERIFYPEER => false,
// CURLOPT_SSL_VERIFYHOST => false,

if (!extension_loaded('curl')) {
	fwrite(STDERR, "Error: PHP curl extension required. Enable it in php.ini.\n");
	exit(1);
}

define('DATA_DIR', is_dir(__DIR__ . '/../data') ? __DIR__ . '/../data' : __DIR__);

$INSECURE = in_array('--insecure', $argv ?? [], true);
if ($INSECURE) {
	fwrite(STDERR, "WARNING: --insecure given — TLS certificate verification is DISABLED.\n");
}
function tlsOptions(): array {
	global $INSECURE;
	if ($INSECURE) {
		return [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false];
	}
	$opts = [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
	if (!ini_get('curl.cainfo')) {
		foreach ([__DIR__ . '/cacert.pem', DATA_DIR . '/cacert.pem'] as $pem) {
			if (file_exists($pem)) { $opts[CURLOPT_CAINFO] = $pem; break; }
		}
	}
	return $opts;
}

// Fetch a URL body (directory listings).
function curlFetch(string $url): string|false {
	$ch = curl_init($url);
	curl_setopt_array($ch, tlsOptions() + [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_USERAGENT      => 'MultiSearch/1.0 (search testbed; contact: github)',
	]);
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return ($code >= 200 && $code < 400) ? $body : false;
}

// Download a large file to disk with a progress line on STDERR.
function curlDownload(string $url, string $dst): bool {
	$fp = fopen($dst, 'w');
	if (!$fp) return false;

	$ch = curl_init($url);
	curl_setopt_array($ch, tlsOptions() + [
		CURLOPT_FILE           => $fp,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT        => 3600,
		CURLOPT_USERAGENT      => 'MultiSearch/1.0 (search testbed; contact: github)',
		CURLOPT_NOPROGRESS     => false,
		CURLOPT_PROGRESSFUNCTION => function($ch, $dlTotal, $dlNow) {
			if ($dlTotal > 0) {
				$pct = round($dlNow / $dlTotal * 100);
				$mb  = round($dlNow / 1024 / 1024);
				$tot = round($dlTotal / 1024 / 1024);
				fprintf(STDERR, "\r  %d%% (%d / %d MB)", $pct, $mb, $tot);
			}
		},
	]);
	$ok   = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	fclose($fp);
	fprintf(STDERR, "\n");

	if (!$ok || $code >= 400) {
		@unlink($dst);
		return false;
	}
	return true;
}

echo "── Wikipedia CirrusSearch dump ──────────────────────────────────\n\n";

$baseUrl = 'https://dumps.wikimedia.org/other/cirrus_search_index/';

echo "Checking available dumps at $baseUrl ...\n";
$listing = curlFetch($baseUrl);
if ($listing === false) {
	fwrite(STDERR, "Error: cannot reach dumps.wikimedia.org\n");
	exit(1);
}

// Parse date directories (YYYYMMDD format)
preg_match_all('/href="(\d{8})\/"/', $listing, $matches);
$dates = $matches[1];
if (empty($dates)) {
	fwrite(STDERR, "Error: no dump dates found.\n");
	exit(1);
}

sort($dates);
$latestDate = end($dates);
echo "Latest dump: $latestDate\n";

// Build URL for simplewiki_content
// URL encoding: the directory name contains '=' which is encoded as %3D
$dumpDir  = $baseUrl . $latestDate . '/index_name%3Dsimplewiki_content/';
$dumpFile = "simplewiki_content-{$latestDate}-00000.json.bz2";
$dumpUrl  = $dumpDir . $dumpFile;
$dumpDst  = DATA_DIR . '/simplewiki-latest.json.bz2';

// Check if we already have this date
$metaFile = DATA_DIR . '/.dump-date';
$existingDate = file_exists($metaFile) ? trim(file_get_contents($metaFile)) : '';

if ($existingDate === $latestDate && file_exists($dumpDst)) {
	echo "Already have dump from $latestDate, skipping download.\n";
	echo "  (delete data/.dump-date to force re-download)\n";
} else {
	// List the directory to confirm the file exists and get its size
	echo "Checking directory listing...\n";
	$dirListing = curlFetch($dumpDir);
	if ($dirListing === false || !str_contains($dirListing, $dumpFile)) {
		fwrite(STDERR, "Error: dump file not found at $dumpUrl\n");
		exit(1);
	}

	// Extract size from directory listing (nginx autoindex format: size in bytes at end of line)
	$size = '?';
	if (preg_match('/' . preg_quote($dumpFile, '/') . '.*?(\d{5,})/', $dirListing, $sm)) {
		$size = round((int)$sm[1] / 1024 / 1024);
	}
	echo "File: $dumpFile (~{$size} MB)\n";

	echo "Downloading...\n";
	$t0 = microtime(true);

	if (!curlDownload($dumpUrl, $dumpDst)) {
		fwrite(STDERR, "Error: download failed.\n");
		exit(1);
	}

	$elapsed = round(microtime(true) - $t0, 1);
	$dlSize  = round(filesize($dumpDst) / 1024 / 1024);
	echo "Downloaded {$dlSize} MB in {$elapsed}s\n";

	// Save date marker
	file_put_contents($metaFile, $latestDate);
}

echo "\n── Next step ───────────────────────────────────────────────────\n\n";
echo "  php scripts/build-index.php    Parse dump → wikipedia.db (production index)\n";
echo "\n";
