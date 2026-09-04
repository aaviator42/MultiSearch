<?php
/*
scripts/download-oewn.php — Download the Open English WordNet JSON zip into
data/.

You normally don't need this: the repo ships data/english-wordnet-2025-json.zip
and the database built from it (data/oewn.db), because both are small. Run
it to refresh the zip — e.g. after deleting it, or after pointing $oewnUrl at
a newer OEWN edition — then rebuild with scripts/build-oewn.php.

Run:  php scripts/download-oewn.php
      php scripts/download-oewn.php --insecure   (skip TLS verification)

── Data source ───────────────────────────────────────────────────────────────
  github.com/globalwordnet/english-wordnet/releases/
  Format: ZIP containing per-POS JSON files (adj.*.json, noun.*.json, etc.)
  ~10 MB compressed, ~80K synsets after filtering.
  License: CC BY 4.0 (attribution required).
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

echo "── Open English WordNet ─────────────────────────────────────────\n\n";

$oewnZip = DATA_DIR . '/english-wordnet-2025-json.zip';

if (file_exists($oewnZip)) {
	echo "OEWN ZIP already present: $oewnZip\n";
	echo "  (delete it to force re-download)\n";
} else {
	/*
	OEWN releases are on GitHub. We hardcode the 2025-edition URL because:
	  - The GitHub releases API requires auth for reliable access
	  - The release tag format isn't guaranteed to stay consistent
	  - We checked: 2025-edition is the latest as of Jul 2026
	  - When a new edition drops, just update this URL

	Tried: scraping the releases page for the latest tag, but GitHub
	rate-limits unauthenticated requests aggressively.
	*/
	$oewnUrl = 'https://github.com/globalwordnet/english-wordnet/releases/download/2025-edition/english-wordnet-2025-json.zip';

	echo "Downloading OEWN 2025 from GitHub...\n";

	if (!curlDownload($oewnUrl, $oewnZip)) {
		fwrite(STDERR, "Error: OEWN download failed.\n");
		exit(1);
	}

	$dlSize = round(filesize($oewnZip) / 1024 / 1024, 1);
	echo "Downloaded {$dlSize} MB\n";
}

echo "\n── Next step ───────────────────────────────────────────────────\n\n";
echo "  php scripts/build-oewn.php     Build synonym database (oewn.db)\n";
echo "\n";
