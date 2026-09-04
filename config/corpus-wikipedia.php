<?php
/*
config/corpus-wikipedia.php — single source of truth for the Simple English
Wikipedia corpus: where it lives, what's special about it, and the default
field weights for searching it.

Why one file: before it existed, the corpus profile was retyped in index.php,
test-suite.php and algo-compare.php, and the 3.0/2.0/1.0 field weights were
retyped in all three as well. The failure mode was silent: tune the profile in
index.php and the test suite keeps validating the OLD profile — every
assertion green while production ranks differently. Now every entry point
(the UI, admin page, test suite, algo-compare, compare-configs) requires this
file, so they cannot drift apart.

A second corpus = a new config/corpus-<name>.php file, not edits to this one.
Plain PHP return-array (not JSON) so we can use __DIR__ paths and keep
commentary like this next to the values.
*/

return [
	// Corpus identity — used to label search-log rows (and, when logs from
	// more than one corpus share a DB, to drive the logs.php filter, which
	// only appears in that case). Adapters: name yours here, nowhere else.
	'name'    => 'wikipedia',

	// Where this corpus lives. Filenames say WHAT the data is (the corpus);
	// HOW it's stored is recorded by the file itself (meta.schema_version /
	// tokenizer_name / positions), so the name doesn't change when the
	// build settings do. Beware the flip side: a wikipedia.db built by an
	// early version of this project has the same name but the old unified
	// schema, which the Searcher cannot read — same filename, incompatible
	// format; rebuild it.
	'db'      => __DIR__ . '/../data/wikipedia.db',
	'oewn_db' => __DIR__ . '/../data/oewn.db',
	// Stopword list. The repo ships config/stopwords.json by default — a
	// hand-curated English list of 118 words — and the demo UI loads it
	// lazily, only when "remove stopwords" is ticked. Point this at your own
	// JSON array of words for another corpus or language, or drop the key
	// (or the file) for no stopword removal at all: the engine itself ships
	// no list, and BM25's IDF plus the high-frequency cutoff already
	// suppress common words. It lives in config/, not data/, because it is
	// hand-curated source, not a build artifact; corpus-specific on purpose,
	// since an English list belongs to an English corpus.
	'stopwords' => __DIR__ . '/stopwords.json',

	// Corpus profile — what's special about THIS corpus. The library assumes
	// nothing about field names; see lib/MultiSearch.php header for all keys.
	'profile' => [
		'title_field'  => 'title',           // earns the exact-match ranking bonus
		'heavy_fields' => ['body'],          // deferred in two-phase "fast mode"
		'field_b'      => [                  // BM25F per-field length normalization
			'title'   => 0.3,                // low b: don't penalize short titles
			'opening' => 0.5,
			'body'    => 0.75,
		],

		// Ranking overrides for THIS corpus — ONLY values deliberately tuned
		// away from the library defaults, with a note on why. The canonical
		// defaults (and per-knob tuning guidance, including suggestions for
		// other corpus types) live in lib/MultiSearch.php RANKING_DEFAULTS —
		// and ONLY there: repeating default values here would pin them
		// against future library improvements and make "deliberately tuned"
		// indistinguishable from "copied boilerplate".
		'ranking' => [
			// (none yet — the library defaults WERE tuned on this corpus)
		],
	],

	// Default field weights for searches against this corpus
	'weights' => ['title' => 3.0, 'opening' => 2.0, 'body' => 1.0],
];
