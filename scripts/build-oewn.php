<?php
/*
scripts/build-oewn.php — Build oewn.db from the Open English WordNet JSON zip.

OEWN provides synonym groups (synsets) that MultiSearch uses for query
expansion: "monster" → also search for "creature", "beast", etc.

Run:  php scripts/build-oewn.php

── Schema ────────────────────────────────────────────────────────────────────
  synsets(id TEXT PK, pos TEXT, members TEXT, definition TEXT)
      members — comma-separated lowercase single-word lemmas in this synset
      definition — first definition string

  word_synsets(word TEXT, synset_id TEXT, sense_order INTEGER)
      sense_order — 0 = most frequent sense
                    nouns: 0–999, verbs: 1000–1999, adj: 2000–2999, adv: 3000+
      INDEX on (word, sense_order) for fast lookup

  derivations(word_a TEXT, word_b TEXT)  PK(word_a, word_b)  WITHOUT ROWID
      derivational/pertainym links between lemmas, stored SYMMETRICALLY
      (both directions), e.g. quickly<->quick, decision<->decide. Extracted
      from the per-sense "derivation" and "pertainym" arrays in the entries
      files; targets are sense keys ("decide%2:31:00::") whose lemma is the
      part before '%'. Uses: (a) verifying risky stem-rule outputs — keep
      quickly->quick, reject summer->sum (unrelated words, no link); (b)
      derivational query expansion — "decision" can also search "decide",
      which suffix rules can't reach. NOT imported: antonym (harmful for
      search), synset-level relations like hypernyms (precision risk —
      needs its own study before use).

── Usage in MultiSearch ──────────────────────────────────────────────────────
  The search UI queries oewn.db for synonyms of each query term:

    SELECT s.members FROM synsets s
    JOIN word_synsets ws ON ws.synset_id = s.id
    WHERE ws.word = ? AND s.members LIKE '%,%'
    ORDER BY ws.sense_order LIMIT ?

  The `LIKE '%,%'` filter skips sole-member synsets (nothing to expand to).
  maxSenses controls how many synsets to merge (1 = first sense only = precise,
  3 = broad). Synonym terms are matched exact-only in the search — never
  fuzzed — to prevent transitive false matches:
    monster → fiend (synonym) ~90%→ friend (fuzzy) at confidence 85.

── Filtering decisions ───────────────────────────────────────────────────────
  We skip multi-word lemmas (with space/underscore/hyphen) because the search
  engine tokenizes on whitespace. "ice cream" as a synonym for "dessert" can't
  be matched as a single token.

  We also skip numeric lemmas and synsets with <2 usable members (sole-member
  synsets have nothing to expand to).

  Tried: keeping hyphenated lemmas and splitting them into separate tokens,
  but this produced too many false positives ("self-made" → "self", "made").

── License ───────────────────────────────────────────────────────────────────
  Open English WordNet: CC BY 4.0 (attribution required).
  Source: https://github.com/globalwordnet/english-wordnet
*/

ini_set('memory_limit', '512M');

define('DATA_DIR', is_dir(__DIR__ . '/../data') ? __DIR__ . '/../data' : __DIR__);
$zipPath = DATA_DIR . '/english-wordnet-2025-json.zip';
$dstPath = DATA_DIR . '/oewn.db';

if (!file_exists($zipPath)) {
	fwrite(STDERR, "Error: english-wordnet-2025-json.zip not found.\n");
	fwrite(STDERR, "Run:   php scripts/download-oewn.php\n");
	exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
	fwrite(STDERR, "Error: cannot open zip.\n");
	exit(1);
}

// POS base offsets ensure nouns come first in sense ordering.
// This matters because noun senses are generally more common/useful
// for synonym expansion than verb/adjective senses.
$posOffset = ['n' => 0, 'v' => 1000, 'a' => 2000, 's' => 2500, 'r' => 3000];

// ── 1. Parse synset files ─────────────────────────────────────────────────

echo "Parsing synset files...\n";
$synsets = [];

for ($i = 0; $i < $zip->numFiles; $i++) {
	$name = $zip->getNameIndex($i);
	if (!preg_match('/^(adj|adv|noun|verb)\./', $name)) continue;
	if (!str_ends_with($name, '.json'))                  continue;

	$data = json_decode($zip->getFromName($name), true);
	if (!is_array($data)) continue;

	foreach ($data as $sid => $s) {
		$rawMembers = $s['members'] ?? [];

		// Filter: lowercase only, skip multi-word / hyphenated / numeric
		$members = [];
		foreach ($rawMembers as $m) {
			if (strpos($m, '_') !== false) continue;
			if (strpos($m, ' ') !== false) continue;
			if (strpos($m, '-') !== false) continue;
			if (is_numeric($m))            continue;
			$members[] = strtolower($m);
		}
		$members = array_values(array_unique($members));
		if (empty($members)) continue;

		$synsets[$sid] = [
			'pos'        => $s['partOfSpeech'] ?? 'n',
			'members'    => $members,
			'definition' => $s['definition'][0] ?? '',
		];
	}
}

echo "  Loaded " . count($synsets) . " synsets.\n";

// ── 2. Parse entries files for sense ordering ─────────────────────────────

echo "Parsing entries files...\n";
$wordSynsets = [];
// Symmetric derivational links, [word_a][word_b] => true for dedupe
$derivations = [];

// Sense-key target ("decide%2:31:00::") -> usable single-word lemma, or null.
$targetLemma = function (string $senseKey): ?string {
	$lemma = strtolower(strstr($senseKey, '%', true) ?: '');
	if ($lemma === '' || is_numeric($lemma))          return null;
	if (strpbrk($lemma, " _-") !== false)             return null;
	return $lemma;
};

for ($i = 0; $i < $zip->numFiles; $i++) {
	$name = $zip->getNameIndex($i);
	if (!preg_match('/^entries-/', $name)) continue;
	if (!str_ends_with($name, '.json'))    continue;

	$data = json_decode($zip->getFromName($name), true);
	if (!is_array($data)) continue;

	foreach ($data as $lemma => $posSenses) {
		if (strpos($lemma, ' ') !== false) continue;
		if (strpos($lemma, '_') !== false) continue;
		if (strpos($lemma, '-') !== false) continue;
		if (is_numeric($lemma))            continue;

		$word = strtolower($lemma);

		foreach (['n', 'v', 'a', 's', 'r'] as $pos) {
			if (!isset($posSenses[$pos])) continue;
			$senses = $posSenses[$pos]['sense'] ?? [];
			$base   = $posOffset[$pos] ?? 9000;
			foreach ($senses as $idx => $sense) {
				$sid = $sense['synset'] ?? null;
				if (!$sid || !isset($synsets[$sid])) continue;
				$wordSynsets[] = [$word, $sid, $base + $idx];

				// Harvest derivational + pertainym links. The JSON
				// flattens relations as per-type keys on the sense; targets
				// are sense keys whose lemma precedes the '%'.
				foreach (['derivation', 'pertainym'] as $rel) {
					foreach ($sense[$rel] ?? [] as $targetKey) {
						$t = $targetLemma($targetKey);
						if ($t === null || $t === $word) continue;
						$derivations[$word][$t] = true;
						$derivations[$t][$word] = true;   // symmetric
					}
				}
			}
		}
	}
}

$zip->close();
echo "  Loaded " . count($wordSynsets) . " word-synset mappings.\n";
$nDerivPairs = array_sum(array_map('count', $derivations));
echo "  Loaded " . number_format($nDerivPairs) . " directed derivation links.\n";

// ── 3. Write SQLite ───────────────────────────────────────────────────────

if (file_exists($dstPath)) {
	unlink($dstPath);
	echo "Removed old oewn.db\n";
}

$db = new PDO("sqlite:$dstPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// journal_mode OFF: single writer, built from scratch, no crash safety needed
// (same as build-index.php). This used to be WAL — and WAL is not a
// connection setting but a persistent FILE flag, so a WAL-built oewn.db made
// every reader (even SQLITE_OPEN_READONLY ones) create -wal/-shm sidecars and
// fail outright on a read-only directory.
$db->exec("PRAGMA journal_mode = OFF");
$db->exec("PRAGMA synchronous  = OFF");

$db->exec("CREATE TABLE synsets (
	id         TEXT PRIMARY KEY,
	pos        TEXT NOT NULL,
	members    TEXT NOT NULL,
	definition TEXT
)");

$db->exec("CREATE TABLE word_synsets (
	word        TEXT    NOT NULL,
	synset_id   TEXT    NOT NULL,
	sense_order INTEGER NOT NULL
)");

$db->exec("CREATE INDEX idx_ws_word ON word_synsets (word, sense_order)");

// Symmetric derivational links (see header). PK(word_a, word_b) is
// also the lookup index for both "are these two linked?" (full PK probe)
// and "what derives from X?" (prefix scan on word_a).
$db->exec("CREATE TABLE derivations (
	word_a TEXT NOT NULL,
	word_b TEXT NOT NULL,
	PRIMARY KEY (word_a, word_b)
) WITHOUT ROWID");

$db->exec("BEGIN");

$stmtS = $db->prepare("INSERT OR IGNORE INTO synsets (id, pos, members, definition) VALUES (?,?,?,?)");
foreach ($synsets as $sid => $s) {
	$stmtS->execute([$sid, $s['pos'], implode(',', $s['members']), $s['definition']]);
}

$stmtW = $db->prepare("INSERT INTO word_synsets (word, synset_id, sense_order) VALUES (?,?,?)");
foreach ($wordSynsets as [$word, $sid, $order]) {
	$stmtW->execute([$word, $sid, $order]);
}

// derivations
$stmtD = $db->prepare("INSERT OR IGNORE INTO derivations (word_a, word_b) VALUES (?,?)");
foreach ($derivations as $a => $targets) {
	foreach (array_keys($targets) as $b) {
		$stmtD->execute([$a, $b]);
	}
}

$db->exec("COMMIT");

$size = round(filesize($dstPath) / 1024 / 1024, 2);
$nSyn = $db->query("SELECT COUNT(*) FROM synsets")->fetchColumn();
$nWS  = $db->query("SELECT COUNT(*) FROM word_synsets")->fetchColumn();
$nDer = $db->query("SELECT COUNT(*) FROM derivations")->fetchColumn();
echo "Built oewn.db: $nSyn synsets, $nWS word-synset rows, $nDer derivation links, {$size} MB\n";

// ── 4. Spot-check ─────────────────────────────────────────────────────────

echo "\nSpot-check (up to 3 senses per word):\n";
$q = $db->prepare("
	SELECT s.members, s.definition, s.pos
	FROM   synsets s
	JOIN   word_synsets ws ON ws.synset_id = s.id
	WHERE  ws.word = ? AND s.members LIKE '%,%'
	ORDER  BY ws.sense_order
	LIMIT  3
");
// Derivation spot-check — the stem-verification use case: linked pairs
// must exist, collision pairs must NOT.
echo "Derivation checks:\n";
$dq = $db->prepare("SELECT 1 FROM derivations WHERE word_a = ? AND word_b = ?");
foreach ([['quickly','quick',true],['decision','decide',true],['runner','run',true],
          ['summer','sum',false],['corner','corn',false],['early','ear',false]] as [$a,$b,$want]) {
	$dq->execute([$a,$b]);
	$got = (bool)$dq->fetchColumn();
	printf("  %-10s <-> %-8s linked=%s  %s\n", $a, $b, $got?'yes':'no ',
		$got === $want ? 'OK' : 'UNEXPECTED');
}
echo "\n";

foreach (['monster','fire','water','time','child','book','road','night','hand','man'] as $w) {
	$q->execute([$w]);
	$rows = $q->fetchAll(PDO::FETCH_ASSOC);
	if (empty($rows)) {
		echo "  $w: (no multi-member synsets)\n";
		continue;
	}
	foreach ($rows as $i => $row) {
		$members = explode(',', $row['members']);
		$others  = array_values(array_diff($members, [$w]));
		$syns    = implode(', ', array_slice($others, 0, 6));
		if (count($others) > 6) $syns .= ', ...';
		$prefix  = $i === 0 ? sprintf("  %-8s", $w) : "          ";
		printf("%s %s) [%d syns] %s\n%s    \"%s\"\n",
			$prefix, $row['pos'], count($others), $syns,
			str_repeat(' ', 11), substr($row['definition'], 0, 70));
	}
}

echo "\nDone. \n";
