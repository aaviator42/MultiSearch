# MultiSearch
A highly modular and adaptable search engine, with support for multiple ranking algorithms and corpus types. It grew out of lessons learned running [iSearch](https://github.com/aaviator42/iSearch) in production.
 

Current library version: `3.5` | `2026-08-25`

License: `AGPLv3`  

## About

MultiSearch is a simple, performant, modular and highly customizable search engine, written in plain PHP.  
Here's some info about it:

 * It is two PHP files (`lib/MultiSearch.php` and `lib/MultiBuilder.php`) on top of SQLite via PDO. 
 * It assumes nothing about your data:
    * Field names are yours (`title`/`body`, or `name`/`ingredients`/`tags`).
    * Document IDs are opaque (integers, UUIDs, file paths, URLs).
    * Everything that is specific to your corpus (which field is "the title", which fields are huge, how much recall to trade for speed, every ranking constant) is declared at runtime as a **corpus profile**, and every one of those settings can also be overridden per search.
 * It supports different corpus styles, such as full-text prose, short names, or tagged data. The code carries suggested starting points for each.
 * It supports full query syntax: required (`+`) and excluded (`-`) words, adjacency-verified `"phrases"`, `wild*` prefixes, and `word^2.5` boosts, all combinable.
 * It expands queries in layers: typo-tolerant fuzzy matching, rule-based stemming, dictionary-verified stemming, morphological expansion, and WordNet synonyms and derivations. Every expansion is score-discounted, so a machine guess never outranks the words the user actually typed.
 * It scales well. The same code searches a ten-document test fixture and the 283,000-article Simple English Wikipedia index (48M postings, 1.1M unique terms, a 1.1 GB SQLite file).
 * It supports 9 scoring algorithms: `auto`, BM25, BM25+Coverage, BM25F, Reciprocal Rank Fusion, DFR, Coverage, Rarity and Frequency. Each is described in [Scoring algorithms](#scoring-algorithms).

## Get started

By default, this repo ships an interface that allows you to play and experiment with MultiSearch using the text of the [Simple English Wikipedia](https://simple.wikipedia.org/) as the corpus, before you adapt it for your own use case.

You need:

 * PHP 8.0 or newer, with the `pdo_sqlite` and `mbstring` extensions (generally included with PHP).
 * For the Wikipedia corpus download step: the `curl` extension (also generally included with PHP).
 * For the build step: either the `bz2` extension, or a `bzcat`, `bunzip2` or `7z` binary on your PATH.
 * About 2 GB of disk space: the dump is ~525 MB, the finished index is ~1.1 GB.

Then, from the repo root:

```bash
# 1. download the Wikipedia CirrusSearch dump (~525 MB) into data/
php scripts/download-wikipedia.php

# 2. build the search index, data/wikipedia.db (a few minutes)
php scripts/build-index.php

# 3. serve the demo
php -S localhost:8080
```

Now open http://localhost:8080/index.php.

The synonym data needs no step of its own: the Open English WordNet zip and the database built from it (`data/english-wordnet-2025-json.zip` and `data/oewn.db`, about 26 MB together) ship with the repo. To refresh them, for example when a new WordNet edition is released:

```bash
php scripts/download-oewn.php   # fetch the zip (edit the URL in the script for a newer edition)
php scripts/build-oewn.php      # rebuild data/oewn.db from it
```

Useful flags:

 * `php scripts/download-wikipedia.php --insecure` (also `download-oewn.php --insecure`) disables TLS certificate verification. Only for machines with a broken CA bundle.
 * `php scripts/build-index.php --no-positions` builds a smaller index (~14% smaller) that can't verify phrase adjacency.
 * `php scripts/build-index.php --no-fold` keeps diacritics as-is, so `café` and `cafe` stay separate terms. By default they are folded together.
 * `php scripts/build-index.php --db=path/to/other.db` builds somewhere else, so you can compare a rebuild against the live index without replacing it.

## The library
MultiSearch consists of two files:

 * `MultiBuilder.php`: builds an inverted index from corpus data
 * `MultiSearch.php`: performs searches on an inverted index

These files can be found in the `lib/` folder in this repo. They must sit in the same directory, because the builder loads the searcher to share its tokenizer and position codec.

A third file, `lib/OewnSynonyms.php`, is optional. It turns Open English WordNet into the synonym and derivation lists the searcher accepts. See [OewnSynonyms reference](#oewnsynonyms-reference).

## Basic Usage

To use MultiSearch with your own data:

1. Copy `lib/MultiBuilder.php` and `lib/MultiSearch.php` into your project.
2. `require` them. There is no autoloader and no composer dependency.
3. Build an index from your documents, then search it.

The easiest way to understand what the library does is to see it in action:

```php
<?php
require 'lib/MultiBuilder.php';
require 'lib/MultiSearch.php';

// ── Build an index ────────────────────────────────────────────────
$b = new \MultiSearch\Builder('recipes.db');

// addText() tokenizes for you. This is the recommended way in,
// because the index and the queries then share one tokenizer.
$b->addText('42', [
    'name'        => 'Spaghetti Carbonara',
    'ingredients' => 'spaghetti eggs pecorino guanciale black pepper',
    'steps'       => 'Boil the spaghetti. Whisk the eggs with cheese...',
], 'Spaghetti Carbonara', 'A Roman classic of eggs, cheese and cured pork.');
//  ^ optional display title  ^ optional opening text, used for snippets

$b->rebuildStats();   // REQUIRED before searching: computes IDF and term stats

// ── Search it ─────────────────────────────────────────────────────
// The profile declares what's special about YOUR corpus. Every key is
// optional; the defaults are neutral (no field is special).
$s = new \MultiSearch\Searcher('recipes.db', [
    'title_field'  => 'name',        // earns the exact-match bonus
    'heavy_fields' => ['steps'],     // deferred in two-phase mode
    'field_b'      => ['name' => 0.2, 'steps' => 0.75],
]);

$result = $s->search('+carbonara egg*', [
    'algo'       => 'bm25+cov',
    'fields'     => ['name' => 3.0, 'ingredients' => 2.0, 'steps' => 1.0],
    'confidence' => 85,              // below 100 enables fuzzy matching
    'stemming'   => true,
]);

foreach ($result['hits'] as $docId => $hit) {
    echo "$hit[score]  $hit[title]\n    $hit[snippet]\n";
}
```

`search()` returns an array like this:

```php
[
  'hits'     => [docId => [
      'score'        => float,    // normalized 0–100, the top hit is always 100
      'title'        => string,   // documents.title if you stored one, else the doc id
      'field_scores' => [field => float],
      'matches'      => [field => [matched terms]],
      'snippet'      => string,   // HTML-escaped, matches wrapped in <mark>
  ]],
  'total'    => int,     // matching docs (see the candidate_limit note below)
  'page'     => int,
  'per_page' => int,
  'pages'    => int,
  'algo'     => string,  // the RESOLVED algorithm ('auto' reports what it picked)
  'diag'     => [...],   // only when 'diagnostics' => true
]
```

## MultiBuilder.php reference

`\MultiSearch\Builder` writes the index. There are two ways in:

 * The **incremental** instance API: `new Builder()` + `addText()`/`addDocument()` + `rebuildStats()`. One transaction per document, in WAL mode, so a live `Searcher` keeps reading while you add and remove documents. Use it for small corpora, live updates, and test fixtures. It is fine up to tens of thousands of documents.
 * **`Builder::bulkBuild()`**: one static call that streams documents into a fresh file. Roughly 10x faster on large imports (the 283K-article Wikipedia index builds in minutes). Use it for anything big.

Both produce the identical schema from one definition.

```php
$b = new \MultiSearch\Builder('index.db');

// pre-tokenized: repeated tokens become term frequency, order becomes positions
$b->addDocument('doc1', [
    'title' => ['php', 'web', 'programming'],
    'body'  => ['php', 'php', 'web', 'tutorial', 'php'],
], 'Display title', 'Optional opening text used for snippets.');

// or raw text: tokenized for you
$b->addText('doc2', ['title' => 'PHP web programming', 'body' => 'PHP tutorial...']);

$b->rebuildStats();   // REQUIRED before searching

// large corpora: stream a generator into a fresh file
\MultiSearch\Builder::bulkBuild('index.db', $generator, ['doc_id_type' => 'INTEGER']);
```

### Functions

#### 1. `new \MultiSearch\Builder(<db path>, <config>)`

Opens or creates an index file. `<config>` is an optional array:

 * `'tokenizer'`: a callable used by `addText()`. Default: the library tokenizer. If you use your own, give the searcher a matching `'term_normalizer'` (see below).
 * `'tokenizer_name'`: a string recorded in the index's `meta` table for provenance.
 * `'fold_diacritics'`: `true` makes `addText()` fold diacritics (`café` → `cafe`) and records that in `meta`, so the searcher folds queries to match. Ignored when you supply your own tokenizer. Default: `false` for new indexes. When you reopen an existing index, the `meta` row decides.
 * `'positions'`: whether to store token positions, which quoted phrases need for adjacency checks. Default: `true` for new indexes. Existing indexes follow their schema, and an explicit value that contradicts it throws, because mixing positional and position-less rows would silently break phrase queries.
 * `'doc_id_type'`: `'TEXT'` (default, any opaque key) or `'INTEGER'` (compact storage, roughly 4 bytes less per posting for 8-digit ids). New indexes only.

Field names must match `[a-z][a-z0-9_]{0,30}` (up to 31 characters), because they become SQL table names. Anything else throws. Per-field tables are created lazily, the first time a field is used.

The file is opened in WAL mode with a 5-second busy timeout, so concurrent writers wait instead of failing. New files get 16 KB pages.

#### 2. `addText(<doc id>, <field texts>, <title>, <opening>)`

Adds or replaces a document from raw text per field:

```php
$b->addText('42', ['name' => 'Spaghetti Carbonara', 'steps' => 'Boil the...'], 'Spaghetti Carbonara', 'A Roman classic.');
```

 * `<field texts>` is `['field' => 'raw text', ...]`. The text is tokenized with the configured or default tokenizer.
 * `<title>` and `<opening>` are optional display strings stored in the `documents` table. The searcher returns the title per hit and builds snippets from the opening.
 * Re-adding an existing doc id replaces it across all fields.
 * Does not update stats. Call `rebuildStats()` when you're done.

#### 3. `addDocument(<doc id>, <fields>, <title>, <opening>)`

Same as `addText()`, but takes pre-tokenized arrays:

```php
$b->addDocument('doc1', ['body' => ['solar', 'solar', 'system']]);
```

 * Repeated tokens become term frequency.
 * Token order becomes positions on a positional index. If you pass unique tokens for binary matching, phrase queries won't match that document.

#### 4. `removeDocument(<doc id>)`

Deletes a document from the postings, the length table, and the `documents` table. Does not update stats.

#### 5. `rebuildStats()`

Recomputes the per-field term statistics, corpus aggregates, and the unique-term list from the current postings. **Must be called after changes and before searching.** Until then, new terms have no IDF and fuzzy matching can't see them.

#### 6. `indexDocuments(<docs>)`

Bulk add plus an automatic `rebuildStats()`:

```php
$b->indexDocuments(['doc1' => ['title' => [...], 'body' => [...]], ...]);
```

Takes pre-tokenized arrays, no titles.

#### 7. `Builder::bulkBuild(<db path>, <docs>, <config>)` (static)

Streams documents into a **fresh** file using staging tables plus one sorted insert per field, which fills each B-tree sequentially without page splits.

```php
$gen = (function () use ($rows) {
    foreach ($rows as $r) {
        yield [$r['id'], ['title' => $r['title'], 'body' => $r['text']], $r['title'], $r['summary']];
    }
})();
$summary = \MultiSearch\Builder::bulkBuild('index.db', $gen, ['doc_id_type' => 'INTEGER']);
```

 * `<docs>` is any iterable yielding `[docId, ['field' => 'raw text' or [tokens...]], ?title, ?opening]`. A generator keeps memory flat for multi-GB inputs.
 * Duplicate doc ids are skipped (first wins) and counted in the summary.
 * Config keys: `positions` (default `true`), `doc_id_type` (default `'TEXT'`), `tokenizer`, `tokenizer_name`, `fold_diacritics` (default `false`), `meta` (extra provenance rows, such as the source file), `batch_size` (documents per staging transaction, default 2000), and `on_progress`, a `callable(string $stage, array $info)` that receives `'parse'`, `'postings'`, `'stats'` and `'vacuum'` events.
 * Refuses to overwrite an existing non-empty file. Delete it first, explicitly.
 * Writes with journaling off and an exclusive lock. The file isn't searchable until the call returns. A failed build is rebuilt from scratch.
 * Returns a summary: `['docs' => int, 'skipped' => int, 'fields' => [f => ['postings' => int, 'terms' => int, 'docs' => int]], 'bytes' => int, 'elapsed' => float]`.

#### 8. `getFields()`, `getTermList(<field>)`, `getStats()`, `hasPositions()`, `docIdType()`

Introspection helpers:

 * `getFields()`: the indexed field names.
 * `getTermList(<field>)`: every distinct term in a field. Reads the stats tables, so it's empty until `rebuildStats()` has run.
 * `getStats()`: `['field' => ['total_docs' => int, 'total_length' => int, 'unique_terms' => int]]`.
 * `hasPositions()`: whether this index stores token positions.
 * `docIdType()`: `'TEXT'` or `'INTEGER'`.

## MultiSearch.php reference

`\MultiSearch\Searcher` reads an index. It has one constructor, one search method, and a handful of static helpers.

```php
$s = new \MultiSearch\Searcher('index.db', ['title_field' => 'title']);
$result = $s->search('+"solar system" planet*', ['algo' => 'auto', 'confidence' => 85]);
```

The index is opened truly read-only (the OS enforces it) with a 2 GB memory map and a 128 MB page cache. Concurrent reader processes don't block each other.

### Functions

#### 1. `new \MultiSearch\Searcher(<db path>, <profile>)`

Opens an index. Configuration errors throw an `InvalidArgumentException` with an actionable message right here: a missing file, a file that isn't a MultiSearch index, or a typo'd ranking knob.

`<profile>` is the **corpus profile**, an optional array. Every key is optional, and every key except `fold_diacritics` can also be passed per search to override it:

| Key | Default | Meaning |
|---|---|---|
| `title_field` | `null` | the field whose exact match earns the title bonus and acts as the tiebreaker. `null` means no field is special |
| `heavy_fields` | `[]` | fields deferred to the second pass of two-phase retrieval |
| `field_b` | `[]` | per-field BM25F length normalization, `['field' => b]`. Unlisted fields use the global `b` |
| `max_wildcard_expansions` | `100` | cap per wildcard prefix, rarest terms first. `0` = no cap |
| `max_fuzzy_per_term` | `10` | fuzzy variants kept per query term. `0` = no cap |
| `highfreq_cutoff` | `0.25` | drop optional terms present in more than this fraction of documents. `1.0` disables. Only active on corpora with 100+ documents |
| `candidate_limit` | `5000` | how many candidates get scored. `0` = score every matching document |
| `phase1_limit` | `1000` | how many survivors of the first pass go on to the second, in two-phase mode |
| `ranking` | `[]` | overrides for the [ranking knobs](#ranking-knobs). Unknown keys throw |
| `stem_verifier` | `null` | `callable(word, root): bool` that unlocks the risky stemming rules. See [Query expansion](#query-expansion) |
| `term_normalizer` | `null` | `callable(term): string` applied to every query term. Required if you indexed with a custom tokenizer |
| `fold_diacritics` | auto | whether query terms are diacritic-folded. Omit it: the searcher reads the index's `meta` table and does the right thing. Set a bool only for an index with no `meta` row |

#### 2. `search(<query>, <options>)`

Runs a query. `<options>` is an optional array:

| Option | Default | Meaning |
|---|---|---|
| `algo` | `'bm25'` | one of `auto`, `bm25`, `bm25+cov`, `bm25f`, `rrf`, `dfr`, `cover`, `idf`, `freq`. Anything else throws |
| `fields` | all fields at 1.0 | `['field' => weight, ...]`. Only these fields are searched. An unknown field throws |
| `confidence` | `100` | 0–100 fuzzy threshold. Below 100 enables fuzzy matching; 85 is a good starting point |
| `page`, `per_page` | `1`, `20` | pagination |
| `stopwords` | `[]` | a list of words to drop from the *optional* terms. `+required` words are never dropped |
| `stemming` | `false` | add root variants of query words (`wolves` also searches `wolf`) |
| `synonyms` | `[]` | word groups, `[['planet', 'world', 'globe'], ...]`. Plain data; see [OewnSynonyms reference](#oewnsynonyms-reference) for a provider |
| `derivations` | `[]` | derivational groups in the same shape (`decision` also searches `decide`) |
| `two_phase` | `false` | score the light fields first, fetch `heavy_fields` only for the top `phase1_limit` survivors |
| `phrase_mode` | `'auto'` | `'unordered'` forces word-level phrase semantics even on a positional index, for A/B comparisons |
| `diagnostics` | `false` | `true` adds a `diag` key to the result. See [Stuff you should know](#stuff-you-should-know) |
| `ranking` | `[]` | ranking knob overrides for this search only |
| any profile key | | overrides the constructor profile for this search |

Returns the array shown in [Basic Usage](#basic-usage). A query that matches nothing returns an empty `hits` array; it never throws.

#### 3. Static helpers and constants

 * `Searcher::VERSION`: the engine version string (`'3.5'`), bumped whenever ranking can change. The demo app keys its result cache and search log on it.
 * `Searcher::ALGOS`: the accepted `algo` values.
 * `Searcher::tokenize(<text>, <fold>)`: the canonical tokenizer. Lowercases, optionally folds diacritics, strips apostrophes (`don't` → `dont`), and turns every other punctuation character into a space (`Coca-Cola` → `coca`, `cola`).
 * `Searcher::foldDiacritics(<text>)`: the folding step on its own (`São Paulo` → `Sao Paulo`, `Straße` → `Strasse`). Non-Latin scripts are untouched.
 * `Searcher::rootWords(<terms>)` and `Searcher::riskyRootCandidates(<word>)`: the stemming rules, exposed so you can inspect what a word stems to.
 * `Searcher::encodePositions()` / `Searcher::decodePositions()`: the delta-varint position codec the builder shares.
 * `$s->foldsDiacritics()`: whether this searcher folds query terms.

## Query Syntax

| Token | Meaning |
|---|---|
| `word` | Optional. Boosts the score if found |
| `+word` | Required. Must appear in at least one searched field |
| `-word` | Excluded. Must not appear in any searched field. With stemming on, `-wolves` also excludes `wolf` |
| `"some phrase"` | On a positional index: the words must appear **adjacent, in order, in one field**. On a non-positional index: all the words are required, anywhere |
| `+"phrase"` / `-"phrase"` | Required / excluded phrase, with the same positional split |
| `word^2.5` | Boost this term's weight by any factor |
| `word*` | Prefix wildcard. Also `+word*` and `-word*` |

Some notes:

 * Modifiers combine: `+creat*^1.5` is a required, boosted wildcard.
 * Precedence is excluded > required > optional.
 * Every term is normalized through the tokenizer, so `don't` finds `dont`, and `coca-cola` searches `coca` + `cola` (adjacent, inside a phrase).
 * Required and excluded words are matched exactly. They are never typo-corrected, because fuzzy-excluding would hide documents the user never asked to hide.
 * Phrase words are never fuzzed or synonym-expanded either.
 * Excluded wildcards are complete: `-americ*` excludes every document containing *any* term with that prefix. The wildcard expansion cap deliberately doesn't apply to exclusions, because completeness is the point of `-`.
 * With stemming on, `+wolves` means "wolves OR wolf": any form satisfies the requirement, and a title containing any form earns the full title bonus.
 * On a positional index, adjacency is checked on the pruned candidate set, so above `candidate_limit` phrase totals are approximate. Pass `'candidate_limit' => 0` for exact totals.

## The demo app

The repo ships a small web app that uses MultiSearch on Simple English Wikipedia. It is meant as a laboratory: pick an algorithm, change field weights, toggle expansion options, and watch the ranking change. It is also the reference for how to wire the library into an app of your own.

Features:

 * `index.php`: the search page.
    * Controls for: algorithm, per-field weights, fuzzy confidence, stemming, stopword removal, WordNet synonyms and derivations (1 to 3 senses), two-phase retrieval, and an "exhaustive" toggle that sets `candidate_limit` to 0 for exact totals.
    * A description of each algorithm, the query syntax, and a set of example queries that each demonstrate one feature.
    * An "Also searched" line under the results that shows every correction and expansion the engine applied, so there is no silent query rewriting.
    * A **result cache** (`data/result_cache.db`): the top 200 ranked hits are cached per query plus every result-affecting setting, index build and engine version, so page 2 onwards slices the cached set instead of re-running the search. Entries expire after 7 days and the newest 500 are kept. Turn it off by setting the `RESULT_CACHE` constant in `index.php` to `false`, for example when measuring real latency.
    * A **search log** (`data/search_log.db`): every query is logged with its settings, the resolved algorithm, timing, result count, the top results, the index build and engine version, whether it was a cache hit, and the client IP. Delete the file after a schema change and it is recreated; there is no migration by design.
 * `admin.php`: index statistics, table sizes, provenance from the index's `meta` table, sample articles, and the build instructions. Stats are cached in `data/admin-cache.json` and refreshed when the index or synonym database changes, because counting 48M rows is not a page-load-time query.
 * `logs.php`: a search-log viewer. Recent searches with an expandable top-3 view, zero-result queries, slowest queries, most common queries, `auto`'s routing distribution, and a per-index-build breakdown so timings are only compared within one build.

Setup: the [Get started](#get-started) steps above. All three pages are marked `noindex` for robots.

Tuning and adapting:

 * `config/corpus-wikipedia.php` is the single source of truth for the demo corpus: paths, the corpus profile, and the default field weights. The UI, the admin page, the test suite and the comparison scripts all read it, so they can't drift apart.
 * To run the demo on your own corpus, add a `config/corpus-<name>.php` with the same keys and point the entry points at it. The engine itself needs no change.
 * `config/stopwords.json` is the 118-word English stopword list the demo loads when "remove stopwords" is ticked. Point the config's `stopwords` key at your own list, or drop the key for no stopword removal.
 * Build-time choices (positions, diacritic folding) are flags on `scripts/build-index.php`, not config, because they describe how the index was built and the index records them itself.

## Stuff you should know

 * **The index and the queries must tokenize identically.** The canonical tokenizer is `Searcher::tokenize()`, and both the builder's `addText()` and the query parser use it, so they can't drift. If you index with your own tokenizer, you must give the searcher a matching `term_normalizer`. Each index records its tokenizer name in its `meta` table, and the searcher reads it to decide whether to fold diacritics in queries.
 * **Diacritic folding is a build-time decision.** A folded index stores `São Paulo` as `sao paulo`, and the searcher folds queries to match, so `sao paulo`, `são paulo` and `"sao paulo"` all find the same article. The trade-off: it conflates real distinctions in some languages (`año`/`ano`). Flipping the setting means a rebuild.
 * **Doc ids are opaque.** Nothing ever parses them. Titles are display data in the optional `documents` table, so duplicate titles are fine.
 * **The `documents` table is optional.** Without it you get no snippets, and `title` falls back to the doc id. Searching is unaffected.
 * **Scores are relative.** The best hit is always 100; a 100 on a garbage query is still garbage. Equal scores tiebreak by title length in tokens when a `title_field` is set (shorter is treated as more canonical: "Water" before "Water pollution"), then by doc id, so ordering is deterministic.
 * **`total` is capped by `candidate_limit`** (default 5000). When more documents match, only the 5000 that contain the most of the user's original query words are scored and counted. Set `'candidate_limit' => 0` when an exact total matters more than latency.
 * **Positions are stored, but only phrases use them.** Positional indexes cost about 16% more space on the Wikipedia corpus. Quoted search phrases verify true adjacency; proximity *scoring* for unquoted multi-word queries still uses a cheaper density heuristic, so ranking and memory are untouched for every query without quotes.
 * **Fuzzy matching applies to the words the user typed, and nothing else.** Required and excluded terms, phrase words, and every machine-generated expansion (stems, synonyms, derivations, morphological variants) are matched exactly. This prevents chains like `monster` → `fiend` (synonym) → `friend` (fuzzy).
 * **Fuzzy candidates must share the query term's first character.** `einstien` → `einstein` is caught; `feinstein` → `einstein` is not. A second-character pass exists in the code but is disabled by default, because it cost about 70 ms per term when testing with the Wikipedia corpus.
 * **Developed with English in mind, but can easily be adapted for other languages.** The stemming rules and morphological suffixes are English, and the demo supplies an English stopword list and WordNet. Everything else (tokenization hooks, scoring, recall caps) is language-neutral. The `term_normalizer` hook is the extension point for other scripts, code identifiers, and so on.
 * **Configuration errors throw; empty results never do.** A wrong index path, a non-index file, an unknown algorithm, an unknown field name, or a typo'd ranking knob all throw `InvalidArgumentException` with a message that says what to fix. A query that matches nothing returns an empty result.
 * **`'diagnostics' => true` opens the engine's reasoning.** The result gains a `diag` key with the parsed query, every expansion with its origin, class and boost, wildcard expansion counts, typo swaps, dropped terms, the candidate funnel, positional phrase checks, and `auto`'s routing evidence. The shape isn't a contract and may change between versions. The default result is byte-identical without it.
 * **Broad queries cost memory.** Posting rows for matched terms are loaded into PHP arrays. The recall caps exist to bound this. The full test suite, which includes deliberately broad queries, peaks at about 360 MB against the Wikipedia corpus.
 * **The searcher never writes to the index.** It opens the file with `SQLITE_OPEN_READONLY`. The incremental builder uses WAL mode with a busy timeout, so a searcher and a builder can work on the same file at the same time. `bulkBuild()` takes an exclusive lock instead, since its output isn't readable until it finishes anyway.
 * **Every `IN()` list is batched at 900 variables**, so SQLite's classic 999-variable limit never bites. `WITHOUT ROWID` tables need SQLite 3.8.2 or newer (2013).

## Scoring algorithms

Pass one of these as `'algo'`. All of them are implemented in `scoreDoc()` and `search()` in `lib/MultiSearch.php`, with their formulas commented; the paper-derived ones cite their sources there too.

1. **`auto`**: Automatically selects the best scoring algorithm based on properties of the search query. Wildcard queries and queries with typo evidence use `freq`, question-shaped queries (how/what/who...) use `bm25f`, everything else uses `cover`. The routing was chosen by a labeled relevance study on the Simple English Wikipedia corpus. You should carry out tests on your own corpus with typical queries and adjust the routing in the code if needed.

2. **`bm25`** (BM25): Computes scores for documents by combining word frequency (with diminishing returns, called TF saturation: the 20th occurrence matters much less than the 2nd), word rarity (IDF), and document length normalization so longer articles don't dominate. Popular for full-text search. The implementation is BM25+ (Lv & Zhai 2011), which adds a small lower bound (`delta`) so very long documents aren't starved to zero.

3. **`bm25+cov`** (BM25+Coverage): `BM25` relevance multiplied by a `coverage` bonus, BM25 × (1 + coverage²). Documents that contain all the search query terms score higher than documents that contain only a subset of the query words, even if the partial matches have higher raw BM25 scores.  
   Useful for general-purpose text search where you want the "obvious" result to rank #1. A good default.

4. **`bm25f`** (BM25F, Robertson et al.): multi-field BM25. Instead of scoring fields (eg: title, opening, and body) separately and then averaging the scores, it combines their term frequencies _first_, weighted by field importance and with per-field length normalization from `field_b`, then scores once, and applies the same coverage bonus as `bm25+cov`.  
   So, instead of treating each field as its own mini-document, we are constructing a "virtual document" first from the fields and then scoring it.  
   This means a title match boosts relevance more naturally than when averaging separate per-field scores, which can produce jumpy/lopsided rankings.

5. **`rrf`** (Reciprocal Rank Fusion, Cormack et al. 2009): combines relevance depth (`BM25`) and query breadth (`coverage`) rankings using the reciprocal rank formula.  
   Each algorithm contributes 1/(60+rank) to each document's final score (the 60 is the `rrf_k` knob). A document ranked #1 by both algorithms scores highest; one ranked high by only one still places well.  
   Good for queries and corpora where neither relevance nor breadth alone surfaces the best results.

6. **`dfr`** (DFR InL2, Divergence From Randomness, Amati & Van Rijsbergen 2002): scores documents by how much a word's frequency exceeds what random chance would predict. If "quantum" appears 5 times in an article but statistics say it should appear ~0.3 times, that strong divergence produces a high score, because it indicates that the term is highly relevant to the topic of the document.  
   No tuning parameters (unlike BM25). Works well with single-word and technical queries.

7. **`cover`** (Coverage): each document's score = the percentage of search query words found in its text.  
   A document containing 3 of 4 terms in the search query scores 75%, regardless of how many times they each appear.  
   Best for when you want "match as many of these terms as possible" without caring about frequency or relevance depth.

8. **`idf`** (Rarity): rarity only: rare words from the search query count more than common ones when computing scores, and rare terms dominate the ranking. When searching for "quantum mechanics", "quantum" contributes more to documents' scores than "mechanics" because it appears in fewer documents.  
   Like `freq`, but distinctive terms are amplified and generic terms are suppressed.

9. **`freq`** (Frequency): each document's score = how often words from the search query appear in it, with diminishing returns: the sum of log(1 + tf) over the matched terms, so 20 occurrences count about 3x as much as two, not 10x. Set the `freq_tf_log` knob to `false` for raw counts, where long documents win outright. There is no length normalization, so longer documents still have an edge. Every matching spelling variant of a word adds up, which is why `auto` picks it for wildcards and typos. Useful when repetition signals topical focus.

Some notes on `auto`:

 * Low `confidence` alone is not typo evidence. A query counts as typo'd only when fuzzy expansion actually promoted a correction, or when a typed word is absent from the index entirely. The `auto_typo_fraction` knob sets how many of the typed words must be suspect; by default one is enough.
 * The question words are `how`, `what`, `which`, `who`, `whom`, `whose`, `when`, `where`, `why`. Detection runs before stopword removal, so they still count if you filter stopwords.
 * The first routing table (single word → `dfr`, multi-word → `bm25f`, fuzzy → `rrf`, wildcard → `bm25`) was intuition, and ranked 6th of 9 in the labeled study. The current one was picked by measurement.

Every algorithm shares the same post-scoring pipeline:

 * **Title bonus**, when a `title_field` is configured. A document whose title contains all the query terms is multiplied by `base + span × ratio²`, where `ratio` is how much of the title the query covers (up to 3× when the query *is* the title). Half or more of the query terms in the title: 1.3×. Fewer: 1.1×. It is computed from indexed postings and token counts, so it works with any doc-id scheme and punctuated titles.
 * **Proximity boost**, for the BM25 family and DFR only, on multi-word queries. It uses a density heuristic over the user's original words (no positions needed) and reaches at most 1 + `proximity_weight`. Set the knob to 0 for tag-like corpora where adjacency means nothing.
 * **Max-normalization** to 0–100 for all.

Coverage is **concept-based** by default: each word the user typed is one slot, and a document matching *any* expanded form (stem, synonym, fuzzy variant) fills that slot once. So a document matching `run` + `running` + `runs` gets credit for one concept, not three. One deliberate exception: RRF's internal coverage component stays per-term, because for rank fusion the number of matched variants is signal, not error (concept mode collapsed its fuzzy ranking in testing). `'concept_coverage' => false` restores per-term accounting everywhere.

## Query expansion

All expansion is discounted by trust. Stems ride at 0.8× the typed word's boost, dictionary-verified risky stems and derivations at 0.7×, morphological variants at a flat 0.7, fuzzy variants at 0.5 per edit of distance. Derived forms add recall; they never outrank exact matches. Fuzzy, synonym and derivation expansion apply to optional terms only. Stemming also applies to required and excluded terms.

 * **Fuzzy** (`confidence` below 100): Damerau-Levenshtein distance against the index's unique-term table.
    * The maximum edit distance scales with term length and confidence: `max(1, floor(length × (100 − confidence) / 100))`. At confidence 85, a 7-letter word allows 1 edit; a 14-letter word allows 2.
    * Candidates are prefiltered by first character and length range, then checked with PHP's C-level `levenshtein()`. The slower pure-PHP Damerau pass runs only on marginal cases where a transposition could bring a candidate under the limit.
    * Capped at `max_fuzzy_per_term` variants (default 10). The exact term always survives the cap.
    * A per-field noise filter then drops fuzzy variants that are far more common than the word they came from, comparing each typed word against its own variants only.
 * **Typo swap**: if a typed word is rare but one of its fuzzy variants is `typo_swap_ratio` times more frequent (default 10×), the *typed* word is treated as the typo: `einstien` (2 documents) is demoted and `einstein` (over 300) promoted, including for the title bonus. Three guards keep it from "correcting" real names, and together they took `auto`'s macro-MRR on the labeled study from .820 to .976:
    * It only fires when the typed word is genuinely rare (`swap_orig_df_max`, default 20 documents).
    * It doesn't fire when the other query words vouch for the typed one by co-occurring in its documents (`swap_context_check`, default 0.3). `rukh` in `shah rukh khan` stays `rukh`.
    * A typed word that matches nothing at all gets its best variant promoted outright when that variant is common enough (`swap_zero_df_promote`, default 100 documents).
 * **Stemming** (`'stemming' => true`): rule-based suffix stripping.
    * Plurals, including irregulars (`children` → `child`, `mice` → `mouse`), `-ies`, `-ves`, `-oes`, `-sses`, `-es`, `-s`.
    * `-ing` and `-ed`, with the doubled consonant undone (`running` → `run`, `stopped` → `stop`) and the silent `e` restored (`hiking` → `hike`).
    * `-ness`/`-iness`, `-ful`/`-iful`, `-ation`, `-ion`, `-ment` (with a length guard so `moment` doesn't become `mo`), `-ize`/`-ise`, `-ical`, `-al`.
    * Plus **morphological expansion** in the other direction: suffixes are *added* (`tunnel` → `tunnelling`, `make` → `making`), which suffix stripping and fuzzy matching both miss. These candidates are only used when they actually exist in the index.
    * Suffix-stripped stems are *not* verified. A phantom stem (`hated` → `hat`) simply matches nothing, or something harmless, and is never fuzzed onward.
    * Porter stemming was tried and rejected: it over-stems (`universe` → `univers`) and loses exact matches.
 * **Verified risky stemming**: some rules often produce real but unrelated words (`summer` → `sum`, `corner` → `corn`). The rules for `-er`/`-est`, `-ly`/`-ily`, `-ous` and `-ity`/`-lity` are generated but only *used* when a dictionary callback (`stem_verifier`) confirms the two words are related. `quickly` → `quick` passes; `summer` → `sum` is rejected. `OewnSynonyms::derivationVerifier()` provides this callback from WordNet's derivation links. Without a verifier, these rules stay off.
 * **Synonyms and derivations**: plain data, `[['planet', 'world', 'globe'], ...]`. The engine is deliberately not coupled to any lexical database. `OewnSynonyms` builds these groups from Open English WordNet, with sense ordering (1 sense = primary sense only, 3 = broad). Derivations reach what suffix rules can't: `decision` also searches `decide`.
 * **Wildcards**: a primary-key range scan over the unique-term table, capped per field at the `max_wildcard_expansions` *rarest* completions (default 100), because rare terms are the most discriminative and have the highest IDF. Without the cap, `th*` expanded to 7,076 body terms and took 78 seconds.
 * **Stopwords**: off by default, because BM25's IDF already suppresses common words and the high-frequency cutoff drops the extreme cases. The engine ships no list; the demo app ships `config/stopwords.json` (118 English words). Only optional terms are ever filtered, so `+the +who` still finds the band.

## Recall vs latency

Four mechanisms deliberately trade recall for speed. All are runtime config (constructor or per search), and all can be disabled:

| Knob | Default | What it hides when active | Disable with |
|---|---|---|---|
| `max_wildcard_expansions` | 100 | documents matching only the *common* completions of a broad wildcard | `0` |
| `max_fuzzy_per_term` | 10 | documents matching only distant fuzzy variants | `0` |
| `highfreq_cutoff` | 0.25 | optional terms present in more than 25% of documents (near-zero IDF anyway; only active at 100+ documents) | `1.0` |
| `candidate_limit` | 5000 | documents beyond the 5000 best-coverage candidates; also caps `total` | `0` |

There is also **two-phase retrieval** (`'two_phase' => true`): score the light fields first, and fetch `heavy_fields` postings only for the top `phase1_limit` survivors (default 1000). Roughly 2× faster on the Wikipedia corpus, but a document that matches *only* in a heavy field is invisible to it, which is why it is opt-in. With no `heavy_fields` declared it silently falls back to standard retrieval.

These defaults exist because the failure modes are real: on the Wikipedia corpus, uncapped broad wildcards took tens of seconds, and unfiltered common words ran a memory-constrained PHP process out of memory. Small corpora can safely disable all of these.

## Ranking knobs

Every scoring constant lives in one place, `RANKING_DEFAULTS` in `MultiSearch.php`, each with a comment on what it does and when to change it. Override any subset in the profile or per search:

```php
$s = new \MultiSearch\Searcher('tags.db', [
    'ranking' => [                 // a tagged-data profile:
        'k1'    => 0.6,            // tags occur once; frequency is meaningless
        'b'     => 0.0,            // tag-list length carries no meaning
        'delta' => 0,              // uniform lengths: the BM25+ lower bound never binds
        'proximity_weight' => 0,   // tag adjacency is storage order, not signal
    ],
]);
```

The full set (23 knobs):

| Knob | Default | What it does |
|---|---|---|
| `k1` | 1.5 | BM25 term-frequency saturation |
| `b` | 0.75 | BM25 length normalization, 0 (none) to 1 (full) |
| `delta` | 0.5 | BM25+ lower bound per occurrence |
| `rrf_k` | 60 | the RRF constant in Σ 1/(k + rank) |
| `proximity_weight` | 0.3 | strength of the density-based proximity boost. 0 disables it |
| `title_bonus_base` | 1.5 | full-title match: `base + span × ratio²` |
| `title_bonus_span` | 1.5 | ... up to 3× when the query is the whole title |
| `title_bonus_half` | 1.3 | at least half the query terms in the title |
| `title_bonus_partial` | 1.1 | fewer than half |
| `fuzzy_decay` | 0.5 | fuzzy variant boost = decay ^ edit distance |
| `typo_swap_ratio` | 10 | a variant this many times more common than the typed word triggers a swap |
| `swap_orig_df_max` | 20 | swap only if the typed word is in at most this many documents |
| `swap_context_check` | 0.3 | don't swap if the other query words vouch for the typed word this strongly |
| `swap_zero_df_promote` | 100 | a typed word with no matches promotes its best variant if that variant is in at least this many documents |
| `noise_exempt_promoted` | true | a promoted correction is exempt from the noise filter |
| `title_promoted_as_form` | true | a promoted correction counts for the title bonus |
| `auto_typo_fraction` | 0.0 | fraction of typed words that must be suspect before `auto` routes to `freq`. 0 = one is enough |
| `freq_tf_log` | true | `freq` uses log(1 + tf) instead of raw counts |
| `concept_coverage` | true | coverage counts typed words, not expanded terms |
| `stem_boost` | 0.8 | discount for stem variants |
| `derivation_boost` | 0.7 | discount for derivations and verified risky stems |
| `morph_boost` | 0.7 | flat boost for morphological variants |
| `snippet_length` | 300 | characters of opening text in a snippet |

The defaults were tuned on the Wikipedia corpus. The comments in the code include suggested starting points for other corpus types:

 * Tagged or keyword data (one occurrence each): `k1` 0.6, `b` 0.0, `delta` 0, `proximity_weight` 0.
 * Short names (products, places): `b` 0.2, `title_bonus_span` 2.0.
 * Noisy user-generated text: `fuzzy_decay` 0.6, `typo_swap_ratio` 5.
 * Technical vocabulary (codes, SKUs, law): `typo_swap_ratio` 100 (rare doesn't mean typo), `fuzzy_decay` 0.3, `morph_boost` 0.5.

These interact, so re-run the test suite (or your own relevance set) after tuning.

## Index schema

One table per field, `WITHOUT ROWID`, so the primary-key B-tree *is* the storage and every term lookup is a covering scan:

```sql
postings_<field>  (term, doc_id, freq, pos)  PK (term, doc_id) WITHOUT ROWID
                  -- pos: 0-based token positions as a delta-varint blob;
                  -- absent when built with --no-positions / 'positions' => false
termstats_<field> (term, doc_freq, idf)      PK (term)          WITHOUT ROWID
doclens      (doc_id, len_<field>...)        PK (doc_id)        WITHOUT ROWID
field_stats  (field, total_docs, total_length)
unique_terms (term, len, fc)                 -- fuzzy and wildcard support
documents    (doc_id, title, opening)        -- optional display data
meta         (key, value)                    -- provenance: schema_version, tokenizer_name,
                                             -- builder, built_at, positions, doc_id_type,
                                             -- plus caller rows (e.g. source_dump)
```

Some notes:

 * `idf` is precomputed at build time, because it depends only on corpus statistics. That removes `log()` calls from the scoring loop.
 * `doclens` merges every field's length into one row, so length normalization is a single fetch.
 * The `meta` table lets an index answer "which tokenizer rules, what source data, when?" long after you've forgotten. The searcher acts on `tokenizer_name`: it folds query diacritics when the index says it was built folded.
 * The first version used one unified `postings` table with a `field` column. Splitting it per field measured about 10× faster on the Wikipedia corpus.

## OewnSynonyms reference

`\MultiSearch\OewnSynonyms` is an optional provider that turns [Open English WordNet](https://github.com/globalwordnet/english-wordnet) into the plain word groups the searcher's `synonyms` and `derivations` options take. It lives outside the engine on purpose: the engine stays free of any coupling to a specific lexical database, its schema, or English, and any other lexicon (or none) plugs in the same way.

It improves English searches in three ways:

 * **Synonyms**: `monster` also searches `creature`, `beast`, and so on.
 * **Derivations**: `decision` also searches `decide`, which suffix rules can't reach.
 * **Stem verification**: it tells the searcher that `quickly` → `quick` is a real relationship and `summer` → `sum` is not, which unlocks the risky stemming rules.

```php
require 'lib/OewnSynonyms.php';

$oewn = new \MultiSearch\OewnSynonyms('data/oewn.db');
$words = \MultiSearch\OewnSynonyms::queryWords($query);

$result = $s->search($query, [
    'synonyms'    => $oewn->groupsFor($words, 1),
    'derivations' => $oewn->derivationsFor($words),
]);

// and in the searcher profile, to unlock risky stems:
$s = new \MultiSearch\Searcher('index.db', ['stem_verifier' => $oewn->derivationVerifier()]);
```

The repo ships the database (`data/oewn.db`) and the zip it was built from. `php scripts/build-oewn.php` rebuilds it from the zip, and `php scripts/download-oewn.php` fetches a fresh zip. It is opened lazily, read-only.

#### Functions

 * `new OewnSynonyms(<db path>)`: the path is required.
 * `ready()`: `true` when the database exists and isn't trivially small.
 * `groupsFor(<words>, <max senses>)`: synonym groups, most common senses first. `1` = primary sense only (precise), `3` = broad. Words with no synonyms produce no group.
 * `derivationsFor(<words>)`: derivationally related words in the same group shape.
 * `derivationVerifier()`: the `stem_verifier` callable, with cached lookups. Returns `null` when `ready()` is false, and then the searcher simply keeps the risky rules off. A database without the derivations table is treated as a broken build and throws on first use: rebuild it.
 * `queryWords(<query>)` (static): strips the query syntax (`+ - " * ^N`) and bare numbers, so only dictionary-shaped words reach WordNet.

What the build imports: synsets with sense order, and derivational and pertainym links, stored in both directions. What it deliberately skips:

 * Antonyms, which are actively harmful for search.
 * Hypernyms and hyponyms, a precision risk that would need its own evaluation first.
 * Multi-word, hyphenated and numeric lemmas, because the tokenizer can't match `ice cream` as one term. Splitting hyphenated lemmas was tried and produced too many false positives.

## Testing

```bash
php scripts/test-suite.php            # the full suite against the real Wikipedia index:
                                      #   tokenizer, ranking, fuzzy, routing, syntax, stemming,
                                      #   performance budgets, positional phrases, diacritic
                                      #   folding, and a labeled ranking study (38 queries x
                                      #   9 algorithms, MRR per category)
php scripts/test-suite.php --fast     # skip the performance and comparison groups
php scripts/test-suite.php --save     # snapshot results to data/test-results/
php scripts/test-suite.php --compare  # diff the last two snapshots (--compare N: last N)
php scripts/test-suite.php --labeled-only
                                      # just the ranking study
php scripts/test-suite.php --db=data/other.db --label=x --save
                                      # run against another index build, e.g. to A/B a rebuild
php scripts/test-suite.php --rk='{"k1":1.2}' --label=k1 --save
                                      # the whole suite under ranking-knob overrides: the
                                      #   assertions gate a proposed tuning, the study scores it
php scripts/compare-configs.php       # labeled snapshots compared side by side
                                      #   (--algo-matrix <config> for the per-algorithm grid)
php scripts/test-api.php              # error contract, diagnostics, Builder::bulkBuild
                                      #   (throwaway fixture indexes, runs in seconds)
php scripts/test-positional.php       # positional phrases: codec, adjacency, non-positional
                                      #   fallback (fixture indexes)
php scripts/algo-compare.php 'query'  # exploration, not assertion: every algorithm side by
                                      #   side with consensus and disagreement marked.
                                      #   --brief, --conf=, --rk='{...}', --target='Title', --db=
```

Some notes:

 * The main suite (251 checks) runs against the real Wikipedia index, not fixtures, and asserts on actual rankings: that `albert einstein` and `bob einstein` each put their own article first, that `+"world war" -"cold war"` returns results, that `football -americ*` still finds thousands of documents, and that phrase hit counts stay within ±3% bands of counts validated against the dump.
 * The labeled study covers eight query categories: clean names, clean topics, phrases, corpus typos, absent typos, name bait, ambiguous, and swap-harmless. On the current build, `auto` and `freq` score a macro-MRR of .976, the other algorithms between .887 and .952.
 * The `--save`/`--compare` cycle exists because ranking changes have non-local effects. Snapshots catch regressions that spot checks miss.
 * `test-api.php` and `test-positional.php` build tiny throwaway indexes and don't need the Wikipedia data.

## Helper scripts

Everything in `scripts/` is part of the Wikipedia demo and the test tooling, not the library. Run them from the repo root. They all read `config/corpus-wikipedia.php` for paths, so they can't disagree with the UI about where the data lives. These scripts are designed to be adapted to different corpuses and use cases. 

**Data pipeline**

 * `download-wikipedia.php`: finds the latest Simple English Wikipedia CirrusSearch dump on dumps.wikimedia.org and downloads it to `data/simplewiki-latest.json.bz2` (~525 MB). Remembers the dump date in `data/.dump-date` and skips the download if you already have that date.
    * `--insecure` turns off TLS certificate verification, with a warning. Only for machines with a broken CA bundle.
 * `download-oewn.php`: downloads the Open English WordNet JSON zip to `data/english-wordnet-2025-json.zip` (~10 MB). Skips if the file exists. By default the zip does ship with this repo. Edit the URL in the script to move to a newer WordNet edition.
    * `--insecure`, as above.
 * `build-oewn.php`: builds `data/oewn.db` from the zip: synsets with sense order, and derivation links stored in both directions. Skips multi-word, hyphenated and numeric lemmas, antonyms, and hypernyms (see [OewnSynonyms reference](#oewnsynonyms-reference)). Prints a spot check of a few words at the end. Ships prebuilt; run it only after refreshing the zip.
 * `build-index.php`: parses the dump and builds `data/wikipedia.db` through `Builder::bulkBuild()`. Indexes three fields (`title`, `opening`, `body`), uses the MediaWiki page id as the document id, and skips talk pages, redirects and duplicates. Takes a few minutes. An existing `data/wikipedia.db` is deleted first and replaced (use `--db` to build elsewhere instead).
    * `--no-positions` skips token positions: a ~14% smaller index that can't verify phrase adjacency.
    * `--no-fold` keeps diacritics as separate characters (`café` ≠ `cafe`). The default folds them.
    * `--db=path` writes the index somewhere else, so you can compare a rebuild against the live index before replacing it.

**Testing and comparison**

 * `test-suite.php`: the main regression suite, run against the real Wikipedia index (251 checks). Tokenizer, ranking, fuzzy matching, routing, query syntax, stemming, performance budgets, positional phrases, diacritic folding, and the 38-query labeled ranking study that scores every algorithm by MRR per query category.
    * `--fast` skips the performance and comparison groups.
    * `--labeled-only` runs just the ranking study.
    * `--save` writes a timestamped JSON snapshot to `data/test-results/`; `--compare` diffs the last two snapshots, `--compare N` the last N.
    * `--rk='{"k1":1.2}'` runs the whole suite under ranking-knob overrides, and `--label=name` names the saved run, so a proposed tuning is gated by the assertions and scored by the study.
    * `--db=path` runs against another index build, for example to A/B a rebuild.
 * `test-api.php`: the API contract (30 checks): configuration errors throw with useful messages, valid queries never throw, the diagnostics channel, and `Builder::bulkBuild()` checked against the incremental build. Uses tiny throwaway indexes, so it runs in seconds and doesn't need the Wikipedia data.
 * `test-positional.php`: positional phrase semantics (22 checks): the position codec, adjacency verification, and the word-level fallback on a non-positional index. Fixture-based, like `test-api.php`.
 * `algo-compare.php`: exploration, not assertion. Runs one or more queries through every algorithm and prints a grid, one row per algorithm, with the consensus top result and every disagreement marked. Useful for deciding what the right answer to a query *is* before recording it in the labeled study.
    * `php scripts/algo-compare.php 'beyonce' 'kim jong un'` runs your queries at confidence 85; with no arguments it runs a built-in set.
    * `--conf=100` sets the fuzzy confidence.
    * `--rk='{...}'` applies ranking-knob overrides.
    * `--target='Pelé'` shows where a known answer ranks under each algorithm and prints a ready-made labeled-study row.
    * `--brief` prints one line per query: word document frequencies, `auto`'s pick, typo swaps, and the top 3.
    * `--db=path` uses another index build.
 * `compare-configs.php`: compares saved labeled-study runs across knob configurations. Reads every snapshot in `data/test-results/`, keeps the latest per label, and prints the regression gate per config, an MRR matrix of config × category, per-query target ranks, and, with `--algo-matrix <config>`, the category × algorithm grid for one config.

## License

Code: GNU Affero General Public License v3.0 (see `LICENSE`).

Bundled data: [Open English WordNet](https://github.com/globalwordnet/english-wordnet) (CC BY 4.0, attribution required), shipped as `data/english-wordnet-2025-json.zip` and `data/oewn.db`.

Data downloaded by the scripts: [Simple English Wikipedia](https://simple.wikipedia.org/) (CC BY-SA 3.0).

-----

Documentation updated: `2026-09-04`
