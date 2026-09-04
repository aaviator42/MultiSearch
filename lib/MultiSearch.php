<?php
/*
MultiSearch.php — Multi-field, multi-algorithm full-text search engine.
v3.5 — Searcher::VERSION is the engine's identity in result-cache keys and
       search logs; it is bumped whenever ranking can change.

Searches an SQLite inverted index built by MultiBuilder.php (or
scripts/build-index.php for the Wikipedia corpus). Scoring: BM25+ (plain,
coverage-weighted, and multi-field BM25F), DFR InL2, Reciprocal Rank Fusion,
coverage, frequency and IDF, plus a query-adaptive 'auto' router — with fuzzy
matching, stemming, wildcard, and synonym expansion layered on top.

The engine is corpus-agnostic by design:
  - No field name is special. Which field acts as "the title", which fields
    are deferred in two-phase retrieval, and per-field BM25F length
    normalization are all runtime config (constructor profile, per-search
    overridable); nothing is hardcoded to 'title'/'opening'/'body'. (The
    first versions were hardcoded that way: the engine grew up on one corpus
    and only later had the corpus knowledge lifted out into the profile.)
  - doc_id is an opaque key (any string or integer). The title bonus and
    tiebreaker read indexed title-field postings + doclens token counts, never
    the doc_id string. Duplicate titles are supported.
  - All recall limits (wildcard expansion cap, fuzzy variants per term,
    high-frequency cutoff, candidate limit) are runtime config; 0 disables.
  - documents table is optional; when present, its title column (if any) is
    returned per hit, and snippets are HTML-escaped before highlighting.
  - Wildcard prefix expansion uses a primary-key range scan instead of the
    fc/len index + LIKE (much faster for broad prefixes).

Originally built as a small testbed against a Frankenstein corpus (~80 pages),
then scaled to Simple English Wikipedia (~200K articles, 1.2M unique terms,
26.8M postings). That jump exposed several critical issues documented below.

── Query syntax ──────────────────────────────────────────────────────────────
  word        optional — boosts score if found
  +word       required — must appear in at least one field
  -word       excluded — must not appear in any field
  "phrase"    ADJACENT words in one field when the index has positions;
              non-positional indexes: all words required (each word becomes
              +required)
  +"phrase"   required phrase
  -"phrase"   excluded phrase (positional: true phrase exclusion;
              non-positional: excludes docs with all the phrase's words in
              one field)
  word^N      apply numeric boost to term (e.g. monster^2)
  word*       wildcard — matches any term with that prefix (optional)
  +word*      required wildcard
  -word*      excluded wildcard
  Combinable: +prog*^1.5   required wildcard with boost

── Corpus profile (constructor config; each key per-search overridable) ──────
  title_field   field whose exact match earns the ranking bonus and acts as
                the equal-score tiebreaker. null = no field is special.
                                                       default: null
  heavy_fields  fields deferred to phase 2 of two-phase retrieval.
                [] = two_phase falls back to standard.  default: []
  field_b       per-field BM25F length-normalization b, ['field' => b].
                Unlisted fields use the global b.       default: []
  max_wildcard_expansions  cap per wildcard prefix, rarest-first. 0 = no cap.
                                                       default: 100
  max_fuzzy_per_term       fuzzy variants kept per query term. 0 = no cap.
                                                       default: 10
  highfreq_cutoff  drop optional terms present in more than this fraction of
                   docs (needs 100+ docs). >= 1.0 disables.  default: 0.25
  fold_diacritics  (constructor only) fold query terms café → cafe.
                   Omit: follows the index's meta.tokenizer_name — the
                   right answer, since folding MUST match the build. Set a
                   bool only for indexes with no meta row.  default: auto
  candidate_limit  max candidates scored (WAND-style coverage prune).
                   0 = score every matching doc.       default: 5000
  phase1_limit     two-phase survivor count.            default: 1000
  stem_verifier    callable(word, root): bool — dictionary check that
                   unlocks the RISKY stem rules (-er/-ly/-ous/-ity); see
                   riskyRootCandidates(). The Wikipedia app wires
                   OewnSynonyms::derivationVerifier(). default: null (risky
                   rules disabled)

── Search options ────────────────────────────────────────────────────────────
  algo        one of Searcher::ALGOS (auto, bm25, bm25+cov, bm25f, rrf,
              dfr, cover, idf, freq); unknown throws   default: bm25
  fields      ['field' => weight, ...]                default: all @ 1.0
  confidence  0-100 fuzzy threshold                   default: 100
  page        result page number                       default: 1
  per_page    results per page                         default: 20
  stopwords   [word, ...]  filter from optional terms  default: []
  stemming    bool  add root variants via rootWords()  default: false
  synonyms    [[word, word, ...], ...]  synonym groups  default: []
  derivations [[word, related, ...], ...] derivational groups (same
              shape; see OewnSynonyms::derivationsFor)  default: []
  two_phase   bool  defer heavy_fields to phase 2      default: false
  phrase_mode 'auto' = adjacency-verify quoted phrases when every
              active field's postings table has a pos column; 'unordered' =
              force word-level phrase semantics (all words required, any
              order/position — what non-positional indexes always do) even
              on a positional index (A/B escape hatch). This value was once
              called 'legacy', which miscast a live mode: non-positional
              indexes are a current build option (--no-positions), not the
              past.                                     default: 'auto'
  diagnostics true = the result gains a 'diag' key — parsed query
              shape, every expansion with origin/class/boost, typo-swap
              events, dropped terms, candidate-funnel counts, auto routing.
              Opt-in; the default path is byte-identical without it. The
              diag shape is NON-CONTRACTUAL (may change between versions)
              and is absent on early-exit empty results.
  ranking     ['knob' => value, ...] overrides into RANKING_DEFAULTS for this
              search only (k1/b/delta, title-bonus curve, expansion boosts,
              typo-swap guards...); unknown keys throw   default: engine defaults
  ...plus any corpus profile key (see above) to override per search

── Return format ─────────────────────────────────────────────────────────────
  [
    'hits'     => [doc_id => ['score'=>float, 'title'=>string,
                              'field_scores'=>[...], 'matches'=>[...],
                              'snippet'=>string], ...],  // snippet: HTML-escaped, <mark>ed
    'total'    => int,   // total matching docs before pagination
    'page'     => int,
    'per_page' => int,
    'pages'    => int,
    'algo'     => string, // the RESOLVED algorithm ('auto' reports its pick)
  ]
  'title' comes from the documents table's title column when present,
  otherwise falls back to the doc_id string.

── Scaling lessons (Frankenstein → Wikipedia) ────────────────────────────────

  1. OOM on common words
     Query "how to prevent eating disorder" crashed with 128MB memory limit.
     Common words like "how", "to" matched nearly every document. fetchPostings
     loaded hundreds of thousands of rows into PHP arrays. Fixed by adding a
     high-frequency term filter that drops optional terms appearing in >25% of
     docs before fetching postings. These terms have near-zero IDF and don't
     contribute to ranking anyway. Only activates on corpora with 100+ docs
     so it doesn't break small test indexes.

  2. SQL variable limit crash
     fetchDocLengths with thousands of doc_ids exceeded SQLite's SQLITE_MAX_
     VARIABLE_NUMBER (default 999). Fixed by adding BATCH_SIZE=900 constant
     and chunking all IN() queries with array_chunk().

  3. Fuzzy search slowness (7-8s per query)
     similar_text() against all 1.2M unique terms was O(n*m²). Added
     fuzzyPrefilter() that filters candidates by first character + length
     range before calling similar_text(). Reduces comparisons ~95%, bringing
     fuzzy queries from 7-8s down to ~5s.
     (Since replaced again: expandFlat() now queries the fc/len-indexed
     unique_terms table and uses C-level levenshtein() with a Damerau
     fallback — fuzzy queries run in tens of milliseconds.)

  4. Title relevance problem
     Searching "Albert Einstein" ranked "Bob Einstein" higher because the body
     of the Bob Einstein article mentioned "Albert Einstein" more. Fixed by
     adding a title exact-match bonus: 2x when all query terms appear in
     title, 1.3x when half or more do. (Since refined into the squared
     title-exactness curve in RANKING_DEFAULTS — the flat 2x did not
     separate exact titles from padded ones well enough.)

  5. Synonym-fuzzy chain explosion
     With synonyms ON and fuzzy ON, chains formed: monster → fiend (synonym)
     → friend (fuzzy match at 85% confidence). Fixed by tracking which terms
     were added by synonym expansion and never fuzzing those — synonym terms
     are matched exact only.

── Things tried and rejected ─────────────────────────────────────────────────

  - Porter stemmer: too aggressive for a search demo. "universe" → "univers"
    lost exact matches. Kept the simpler rootWords() suffix-stripping instead,
    which handles plurals, -ing, -ed, -ful without over-stemming.

  - Storing BM25 scores at index time: considered precomputing BM25 during
    build for faster search. Rejected because scores depend on query terms
    (IDF) and field weights (user-configurable at search time).

  - Caching term lists in memory: tried loading all terms once at Searcher
    construction. Worked fine for Frankenstein but used 200MB+ for Wikipedia.
    Now loaded on-demand only when fuzzy is enabled (confidence < 100).

  - Position-based proximity SCORING: considered ranking "United States"
    higher when the words appear adjacent vs. far apart, using real token
    positions. Positions were eventually stored anyway (delta-varint blobs,
    measured +15.8% index size) because quoted phrases needed true adjacency
    — but they are decoded only for phrase words on the pruned candidate
    set. Proximity RANKING for unquoted multi-word queries still uses the
    cheap density heuristic: decoding positions on the hot path for every
    query has not shown a measured need.
*/

namespace MultiSearch;

class Searcher
{
    private \PDO $db;

    // ── Ranking profile ──────────────────────────────────────────────────────
    // Every tuning constant that shapes scoring, in ONE place, with the
    // canonical defaults. Override any subset via constructor config
    // ['ranking' => [...]] or per search ($options['ranking']); unknown keys
    // throw so a typo'd knob fails loudly instead of silently no-opping.
    //
    // Earlier these values were delivered through three different
    // mechanisms that grew historically — k1/b via a mutable setBM25Params()
    // method (BM25 was the first tunable algorithm, so it got a setter),
    // delta/rrf_k/proximity/title-bonus/fuzzy-decay/etc. as hardcoded literals
    // scattered through the file. field_b DELIBERATELY stays in the corpus
    // profile: its keys are field names (corpus vocabulary); these are
    // portable scalars.
    //
    // ── Suggested starting points by corpus type ────────────────────────────
    //   Long-form prose (wiki, docs, news) ... the defaults below (tuned here)
    //   Tagged/keyword data (labels, tags,
    //     categories — one occurrence each) . k1 0.6, b 0.0, delta 0,
    //                                          proximity_weight 0
    //   Short names (products, places) ...... b 0.2, title_bonus_span 2.0
    //   Noisy user-generated text ............ fuzzy_decay 0.6, typo_swap_ratio 5
    //   Technical vocab (codes, SKUs, law) ... typo_swap_ratio 100 (rare ≠ typo),
    //                                          fuzzy_decay 0.3, morph_boost 0.5
    // Always re-run scripts/test-suite.php (or your own relevance set) after
    // tuning — these interact.
    private const RANKING_DEFAULTS = [

        // ── BM25 family ──────────────────────────────────────────────────────
        // k1 — term-frequency saturation: how much REPEATED occurrences keep
        // adding score. The 2nd occurrence always matters more than the 20th;
        // k1 sets how fast that flattens. Higher (1.8-2.5): repetition reads
        // as topical focus — long prose where "the article keeps saying
        // quantum" is signal. Lower (0.5-1.2): approaches binary match/no-match
        // — right for short fields and TAGGED DATA, where a tag occurs once
        // and frequency is meaningless. Lucene default 1.2; we use 1.5 because
        // Wikipedia body text rewards repetition slightly more.
        'k1'                  => 1.5,

        // b — document-length normalization, 0 (none) to 1 (full). Penalizes
        // long docs so they can't win on sheer word count. High (0.75-1.0):
        // corpora with wildly varying lengths (stubs vs 10K-word articles).
        // Low (0-0.3): length variation carries no meaning — tag lists, titles,
        // product names — where normalization would just add noise. For
        // per-field control under BM25F use the corpus profile's field_b
        // (e.g. title 0.3 here: short titles are normal, don't punish them).
        'b'                   => 0.75,

        // delta — BM25+ lower bound (Lv & Zhai 2011): guarantees every
        // occurrence contributes at least delta*idf, so very long docs aren't
        // starved to ~zero TF score. Raise toward 1.0 if long docs you KNOW
        // are relevant keep losing to stubs; 0 = classic BM25, fine when doc
        // lengths are uniform (then the bound never binds).
        // Tried here: 1.0 — too strong, favored long docs; 0.5 is the balance.
        'delta'               => 0.5,

        // ── Fusion / proximity ───────────────────────────────────────────────
        // rrf_k — Reciprocal Rank Fusion constant: score = Σ 1/(k + rank).
        // Small k (10-30): the #1 spots dominate the fusion — sharper, trusts
        // each component ranker's head. Large k (100+): flattens rank
        // differences, consensus across rankers wins — safer when components
        // are noisy (which is why fuzzy queries use RRF here). 60 is the
        // standard value from Cormack et al. 2009; rarely worth touching.
        'rrf_k'               => 60,

        // proximity_weight — strength of the density-based proximity boost
        // (multiplier reaches 1 + weight). Raise (0.5+) when multi-word
        // queries usually mean phrases — names, titles, "new york" — and
        // co-location is strong evidence. Set 0 to DISABLE the whole block
        // for bag-of-words corpora (tags/categories: adjacency is an artifact
        // of storage order, not meaning).
        // Tried here: raw un-normalized boost — overwhelmed other signals.
        'proximity_weight'    => 0.3,

        // ── Title exactness curve ────────────────────────────────────────────
        // Full-title match bonus = base + span * lenRatio², where lenRatio is
        // how much of the title IS the query (title "Albert Einstein" for
        // query "albert einstein" → 1.0 → base+span; "Albert Einstein Square"
        // → 0.67 → ~2.17x with defaults). Raise span (2.0+) for NAVIGATIONAL
        // corpora where users search for a document by its name (products,
        // encyclopedia). Shrink toward base=span=0.5, or set title_field null
        // in the corpus profile, when the "title" is descriptive rather than
        // canonical (e.g. forum thread subjects) and body relevance should win.
        // The first version was a flat 2.0x/1.3x — not enough spread for BM25;
        // the squared curve separates exact titles from padded ones.
        'title_bonus_base'    => 1.5,
        'title_bonus_span'    => 1.5,   // → up to 3.0x for title == query
        'title_bonus_half'    => 1.3,   // ≥50% of query terms in title
        'title_bonus_partial' => 1.1,   // <50% — barely a nudge

        // ── Expansion boosts ─────────────────────────────────────────────────
        // fuzzy_decay — fuzzy variant boost = decay^editDistance. Lower (0.3):
        // typo variants are strongly subordinate — clean/controlled vocab
        // where near-spellings are DIFFERENT things (SKUs, chemical names).
        // Higher (0.6-0.7): forgiving — noisy user-generated content. History:
        // tried pow(confidence/100, dist) → only 15% discount at conf 85,
        // BM25's short-doc advantage steamrolled it ("Shart" outranked
        // "Smart"); tried 0.7 — still not enough; 0.5 held across all algos.
        'fuzzy_decay'         => 0.5,

        // typo_swap_ratio — the noise filter treats a QUERY term as a typo
        // when some fuzzy variant is this many times more frequent in the
        // corpus ("einstien" df=3 vs "einstein" df=300+ → swap). Lower (3-5):
        // aggressively assume users mistype — consumer search. Higher (50-100+):
        // rare terms are legitimate vocabulary (scientific terms, part
        // numbers, case citations) — rare ≠ wrong there. 10 is conservative:
        // only fires on ~order-of-magnitude gaps.
        'typo_swap_ratio'     => 10.0,

        // ── Typo-swap guards (all default ON after a labeled-study sweep:
        //    auto macro-MRR .820 -> .976) ────────────────────────────────────
        // The df-ratio heuristic above cannot tell a rare TYPO from a rare
        // NAME: "shah rukh khan" — rukh df=88 vs fuzzy variant rush df=1366
        // (15.5x) — swapped rukh->rush, routed auto to freq, and put Paula
        // Abdul's "Rush Rush" on page 1. These knobs add the missing evidence.
        //
        // swap_context_check — before swapping a term, ask whether the OTHER
        // query words vouch for it: the fraction of the original term's docs
        // that also contain another query word. rukh co-occurs with shah/khan
        // in nearly all its docs (it's a name in context); einstien's typo
        // docs mostly don't contain a second query word. Value is the minimum
        // vouch ratio that BLOCKS the swap; 0 disables (always swap). Only
        // meaningful on multi-word queries — a lone word has no vouchers.
        'swap_context_check'  => 0.3,   // on: part of the guard set behind the sweep's .820 -> .976

        // swap_orig_df_max — only swap when the original term is genuinely
        // rare (df at or below this). Real corpus typos sit at df 0-5
        // ("einstien" df=3); df=88 ("rukh") is an established word. 0 = no
        // ceiling (ratio test alone decides, the historical behavior).
        'swap_orig_df_max'    => 20,    // on: dfmax 5 blocked the real 'shakespear' (df=8) swap

        // swap_zero_df_promote — a typed word with df=0 is the STRONGEST typo
        // evidence there is, yet the swap above requires df>0 to fire, so
        // "wikipeedia" got no promotion at all: its correction kept the 0.5
        // fuzzy boost, was dropped by the per-field noise filter wherever
        // common, and earned no title bonus — "Scots Wikipedia" (containing
        // the rare misspelling "wikipaedia") outranked "Wikipedia" 100 to 50.
        // Value is the minimum doc_freq a fuzzy variant needs to be promoted
        // to boost 1.0 when the typed word has df=0. There is no demotion —
        // a df=0 original matches nothing anyway. 0 disables.
        'swap_zero_df_promote' => 100,   // on: 50 promoted 'restraint' for 'restraunt'; 500 lost beethoven (df=498)

        // noise_exempt_promoted — the per-field noise filter drops fuzzy
        // variants that are common IN THAT FIELD (>max(1000, 3x original)),
        // sparing only the user's literally-typed words. When the swap has
        // just promoted a variant as the user's intended word, filtering it
        // back out is self-sabotage: promoted "wikipedia" (body df 195,616)
        // survived only in title. true = promoted variants bypass the fuzzy
        // noise threshold (the corpus-stopword cutoff still applies).
        'noise_exempt_promoted' => true,  // on: only safe WITH the swap guards above (solo: macro .720)

        // title_promoted_as_form — count a promoted variant as a FORM of the
        // word it corrects, not as an extra query concept. As its own concept
        // it dilutes everyone's title match ratio: promoted "rush" made the
        // query 4 concepts, so the title "Shah Rukh Khan" matched 3/4 → the
        // 1.3x half bonus instead of the 3.0x full-match bonus — that single
        // downgrade kept the exact-title article off #1. Same slot idea as
        // concept_coverage: "rukh or rush in the title" fills one slot.
        'title_promoted_as_form' => true,

        // ── Auto-routing / freq scoring (same sweep) ─────────────────────────
        // auto_typo_fraction — auto routes to freq only when at least this
        // fraction of the typed words carry typo evidence (a swap fired or
        // df=0). At the historical 0, ONE suspect word among many flips the
        // whole query to freq — "shah rukh khan" (1 of 3 suspect, and falsely
        // so) was scored by raw term count and Genghis Khan's long body won.
        // 0 = any evidence routes (historical); 0.5 = majority; 1.0 = all.
        'auto_typo_fraction'  => 0.0,

        // freq_tf_log — freq scores log(1+tf) per term instead of raw tf,
        // damping the long-document blowout (a body mentioning "khan" 50
        // times scores ~4, not 50) while keeping freq's strength: rewarding
        // docs that match MANY variants of a typo'd word. false = raw tf.
        'freq_tf_log'         => true,  // on: freq .796 -> .976 on the labeled set

        // concept_coverage — compute coverage over CONCEPTS (words the user
        // typed) instead of individual expanded terms. Per-term accounting
        // let a doc harvest one concept several times: "Run" matched
        // run+running+runs (~2.4 credit for ONE typed word) and outranked the
        // exact-form "Running" article. With concepts, a doc matching ANY form
        // fills that concept's slot once, at the matched form's boost, capped
        // at the concept's weight. Affects cover, the bm25+cov multiplier,
        // and BM25F coverage; the per-term TF/IDF score sums are untouched;
        // freq/idf/bm25/dfr have no coverage term, so it's naturally a no-op
        // there.
        //
        // Default ON after a 19-query expansion-heavy A/B (labeled MRR):
        //   stemmed  cover .944->1.000, bm25+cov .833->.944 (Run/Running fixed,
        //            governments -> Government)
        //   fuzzy    cover .667->.722 (einstien -> Albert Einstein #1)
        //   wildcard cover .500->.833 (shakesp* -> William Shakespeare #1)
        //   bm25f neutral; bm25 control bit-identical; zero regressions.
        // DELIBERATE EXEMPTION: RRF's internal coverage component stays
        // per-term REGARDLESS of this flag — for rank fusion, "how many forms
        // did this doc match" is discrimination, not error; concept mode
        // collapsed its coverage ranking into ties and noise (fuzzy MRR
        // .625->.417, 'volcanoe' target 1->9). See the rrf branch in search().
        // Set false to restore per-term accounting everywhere.
        'concept_coverage'    => true,

        // stem_boost — weight for root variants added by rootWords() when
        // stemming is on ("wolves" query matching "wolf" docs). Before this
        // knob existed, stem variants rode at full 1.0 — the only expansion class
        // with no discount — so a rule collision (hated → "hat") counted as
        // much as the typed word. The discount ladder matches how much we
        // trust each expansion class: stems 0.8 > morph 0.7 > fuzzy 0.5 —
        // a stemmed form is almost always the same word, a morph variant
        // usually is, a fuzzy variant is often a different word entirely.
        'stem_boost'          => 0.8,

        // derivation_boost — weight for dictionary-verified derivational
        // expansions: OEWN-linked related words ("decision" also
        // searching decide) supplied via the 'derivations' search option, and
        // risky stem-rule roots that passed the 'stem_verifier' check
        // (quickly->quick). Trust ladder: stems .8 > derivations/morph .7 >
        // fuzzy .5^d — a rule-stripped inflection is almost surely the same
        // word; a derivation is a closely related one; fuzzy is a guess.
        'derivation_boost'    => 0.7,

        // morph_boost — weight for morphological variants added by stemming
        // ("tunnel" query matching "tunnelling" docs). Raise (0.9) where
        // inflection barely changes meaning (news prose); lower (0.5) where
        // morphology is semantic — legal terms, code identifiers ("parser"
        // vs "parsing" may be different concepts).
        'morph_boost'         => 0.7,

        // ── Display (no ranking impact) ──────────────────────────────────────
        // snippet_length — chars of opening text before highlighting.
        'snippet_length'      => self::SNIPPET_LENGTH,
    ];

    private array $ranking = self::RANKING_DEFAULTS;

    // Max SQL variables per IN() clause. SQLite's default SQLITE_MAX_VARIABLE_NUMBER
    // is 999. We use 900 to leave room for other params in the same query.
    // This was added after a crash on Wikipedia-scale queries where fetchDocLengths
    // tried to bind thousands of doc_ids in a single IN() clause.
    private const BATCH_SIZE = 900;

    // Wildcard max expansions: cap how many terms a single prefix wildcard can expand to.
    // Without this, "th*" expands to 7,076 body terms → 991K postings → 78s.
    // With cap at 100 (sorted by doc_freq ascending = rarest first), we get the most
    // discriminative terms while keeping expansion fast.
    // Elasticsearch defaults to 50 for prefix queries. We use 100 for better recall.
    // Tried: no cap — broad wildcards like "th*" took 78s. 200 still slow (~15s).
    // 100 gives good balance: fast + good ranking (rare terms have highest IDF).
    private const MAX_WILDCARD_EXPANSIONS = 100;

    // Fuzzy expansion per-term limit: max fuzzy variants per original query term.
    // Without this, "history" at confidence 85 generates 16 fuzzy variants like
    // "histor", "histoy", "hstory" etc. — mostly noise that inflates the candidate
    // doc set. With 7 query terms × 16 variants = 112 terms matching 172K docs → 43s.
    // Capping at 10 per term reduces total terms and candidate docs significantly.
    // Tried: unlimited — long fuzzy queries took 43s.
    // Tried: 5 — too aggressive, lost legitimate variant matches.
    // 10 is a good balance: keeps top matches, drops noise.
    private const MAX_FUZZY_PER_TERM = 10;

    // ── Algorithms ───────────────────────────────────────────────────────────
    // ALGOS is a plain KEY LIST const. It used to be a public mutable static
    // array of [key => long English description] — which had two problems.
    // It mixed engine truth (which keys search() accepts) with UI copy (the
    // descriptions now live in index.php's $algoDescs, where presentation
    // belongs). Worse, it LOOKED like a registry — add a key, get an
    // algorithm — but capabilities are hardcoded in scoreDoc()'s switch and
    // the bm25f/rrf branches in search(); a key added here would have produced
    // searches that silently score zero. A const key list can't pretend to be
    // an extension point, and it powers the fail-fast algo validation
    // in search(). Real scorer pluggability, if ever wanted, is a separate
    // design (profile callables), deliberately not faked here.
    //
    // Per-algo design history:
    //  - bm25+cov: BM25 * (1 + coverage^2). Multiplicative, not additive —
    //    additive blending needs per-corpus weight tuning, while a 2x title
    //    bonus doubles a score whether BM25 says 5 or 500. Tried:
    //    MeiliSearch-style bucket sort (too rigid); linear coverage,
    //    BM25 * (1 + coverage), which shipped for a while as its own algorithm
    //    (squared surfaces the "obvious" result better and replaced it).
    //  - bm25f: Robertson et al. single-pass multi-field BM25 — blends field
    //    TFs into a virtual TF (weighted, per-field length normalization via
    //    the profile's field_b), then scores once; avoids the averaging
    //    artifacts of per-field BM25. Used by Elasticsearch/Sphinx/edismax.
    //  - rrf: Reciprocal Rank Fusion (Cormack, Clarke & Buettcher 2009):
    //    independent BM25 and coverage RANKINGS fused via sum(1/(k+rank)),
    //    k=60 from the paper ('rrf_k' knob). Robust for noisy/fuzzy queries.
    //  - dfr: DFR InL2 (Amati & Van Rijsbergen 2002) — divergence from
    //    randomness; parameter-free; strong on single-word queries (used in
    //    Terrier and Lucene/Solr). Formula documented at the scoreDoc case.
    //  - auto: query-adaptive routing, chosen by a 75-query labeled study
    //    (macro-MRR .890 vs .839 for the best single algorithm): wildcards ->
    //    freq, typo EVIDENCE -> freq (deferred until fuzzy runs; see
    //    AUTO_PENDING), question-shaped -> bm25f, else -> cover. The first,
    //    intuition-based mapping (single->dfr, multi->bm25f, fuzzy->rrf,
    //    wildcard->bm25) ranked 6th of 9 in that study.
    public const ALGOS = [
        'auto', 'bm25', 'bm25+cov', 'bm25f', 'rrf', 'dfr',
        'cover', 'idf', 'freq',
    ];

    // ── Stopwords ────────────────────────────────────────────────────────────
    // The ENGINE ships no stopword list: stopwords only ever arrive via the
    // 'stopwords' search option. Language data is app data — the same
    // boundary synonyms sit behind — and a hardcoded English list inside a
    // language-neutral core was a wart. When one did live here (a public
    // static array) it silently drifted from the app's JSON file for months
    // (76 vs 110 words, extras on BOTH sides), because which list a search
    // used depended on whether the JSON file existed.
    // The REPO does ship a list: config/stopwords.json (118 curated English
    // words, the reconciled union of the two), wired in through the corpus
    // config's 'stopwords' key and loaded by the demo app. Point that key at
    // your own file for another language or domain, or pass no list at all:
    // stopword removal is off by default anyway, since BM25's IDF and the
    // highfreq_cutoff filter already suppress common terms.
    // Contract: stopwords are filtered from OPTIONAL terms only — +required
    // and phrase words are never stopword-filtered ("+the +who" still finds
    // the band).

    // Pre-computed IDF values loaded by fetchTermStats(), keyed by [$field][$term].
    // Eliminates log() calls in scoreDoc — IDF only depends on N and df, both fixed
    // for a given index. Computed at build time, stored in termstats_* tables.
    private array $termIdfByF = [];

    // ── Tokenization contract ────────────────────────────────────────────────
    // The index and the query MUST tokenize identically, and for most of this
    // project's life they DIDN'T: build-time tokenize() (a private copy in the
    // build script) stripped apostrophes and split on all punctuation, while
    // parseQuery() kept ' . - inside terms. Result, measured on the production
    // index: query "don't" → 0 hits while "dont" → 2,938; "coca-cola" → 0
    // while "coca cola" → 553 with Coca-Cola #1. No error, just missing
    // results. The two implementations agreed by coincidence and drifted.
    //
    // Fix: ONE canonical implementation lives here; the build pipeline calls
    // it (scripts/build-index.php deleted its private copy), and parseQuery()
    // normalizes every query term through the same rules.
    //
    // TOKENIZER_DEFAULT is a FORMAT IDENTIFIER, not a preference: if these
    // rules ever change (say, keeping hyphens), bump to -v2 — changed rules
    // require a rebuild, and indexes record which rules built them in their
    // meta table. For a long time that was provenance only — enforcement was
    // deliberately skipped until a second tokenizer actually existed.
    public const TOKENIZER_DEFAULT = 'multisearch-default-v1';

    // That second tokenizer now exists — the same rules plus diacritic
    // folding (café → cafe, São → sao, Straße → strasse; see foldDiacritics).
    // Same code path (tokenize() with $fold = true), a different vocabulary
    // shape, hence a different format identifier. And with two identifiers
    // the meta row stops being provenance-only: the constructor reads it
    // and folds query terms iff the index was built folded (config
    // 'fold_diacritics' overrides). Measured on the Simple English Wikipedia
    // index before folding: 90,521 accented-Latin terms, and the common
    // unaccented spelling missed most of the corpus — body df sao 157 vs
    // são 1,069, francois 176 vs françois 1,010, title "pokemon" 0 vs
    // pokémon 54.
    public const TOKENIZER_FOLDED = 'multisearch-fold-v1';

    // Engine version marker. Consumed by diagnostics — e.g. the demo app's
    // search log and result cache record it so before/after comparisons
    // across engine changes stay attributable. Bump whenever ranking can
    // change.
    public const VERSION = '3.5';

    // Sentinel for deferred auto-algorithm resolution — conf<100 queries
    // can't be classified as "typo'd" until fuzzy expansion has run (see the
    // auto-selection block and its AUTO_PENDING resolution in search()).
    private const AUTO_PENDING = '__auto_pending__';

    // Custom per-term query normalizer: callable(string $term): array.
    // Constructor config 'term_normalizer'. Escape hatch for corpora built
    // with a non-default tokenizer (CJK, code identifiers, hyphen-significant
    // vocabularies) — MUST match the rules the index was built with.
    private ?\Closure $termNormalizer = null;

    // Fold diacritics in query terms (see TOKENIZER_FOLDED). Resolved
    // in the constructor: config 'fold_diacritics' (bool) wins; absent/null
    // means "whatever the index's meta.tokenizer_name says".
    private bool $foldDiacritics = false;

    // callable(string $word, string $root): bool — dictionary check for
    // RISKY stem-rule candidates (see riskyRootCandidates). Constructor config
    // 'stem_verifier' (per-search overridable); the Wikipedia app wires
    // OewnSynonyms::derivationVerifier(). null = risky rules stay disabled.
    private ?\Closure $stemVerifier = null;

    // Corpus profile — see header. Set via constructor config, overridable
    // per search. Defaults are library-neutral: no field is special.
    private ?string $titleField = null;
    private array   $heavyFields = [];
    private array   $fieldB = [];
    private int     $maxWildcardExpansions = self::MAX_WILDCARD_EXPANSIONS;
    private int     $maxFuzzyPerTerm = self::MAX_FUZZY_PER_TERM;
    private float   $highfreqCutoff = 0.25;
    private int     $candidateLimit = 5000;
    private int     $phase1Limit = 1000;

    // Lazy documents-table introspection: null = not checked yet, [] = table
    // absent, otherwise the list of column names. Makes the documents table
    // optional (minimal indexes without snippets/titles must not throw).
    private ?array $documentsCols = null;

    // Per-search memo for wildcard prefix candidates (see
    // prefixCandidates()). The unique_terms range scan is field-independent,
    // but expandPrefix() is called once per active field per prefix — this
    // cache makes the scan run once per prefix instead of once per field.
    // Cleared at the top of every search() call.
    private array $prefixCandidateCache = [];

    // Snippet truncation length (chars, before highlighting). Matches the
    // display length the UI used when it built snippets itself.
    private const SNIPPET_LENGTH = 300;

    public function __construct(string $dbPath, array $config = [])
    {
        // Earlier the constructor took only the path, and corpus knowledge
        // (which field is the title, which are heavy, BM25F b values) was
        // hardcoded at the usage sites instead of being declared by the caller.
        $this->titleField            = $config['title_field'] ?? null;
        $this->heavyFields           = $config['heavy_fields'] ?? [];
        $this->fieldB                = $config['field_b'] ?? [];
        $this->maxWildcardExpansions = (int)($config['max_wildcard_expansions'] ?? self::MAX_WILDCARD_EXPANSIONS);
        $this->maxFuzzyPerTerm       = (int)($config['max_fuzzy_per_term'] ?? self::MAX_FUZZY_PER_TERM);
        $this->highfreqCutoff        = (float)($config['highfreq_cutoff'] ?? 0.25);
        $this->candidateLimit        = (int)($config['candidate_limit'] ?? 5000);
        $this->phase1Limit           = (int)($config['phase1_limit'] ?? 1000);

        // Ranking overrides — only deliberately tuned deviations belong
        // here (apps keep them in their corpus config); the canonical defaults
        // live in RANKING_DEFAULTS above and ONLY there.
        if (!empty($config['ranking'])) {
            self::checkRankingKeys($config['ranking']);
            $this->ranking = array_replace($this->ranking, $config['ranking']);
        }

        // Custom query-term normalizer (see tokenization contract above).
        if (isset($config['term_normalizer'])) {
            $this->termNormalizer = \Closure::fromCallable($config['term_normalizer']);
        }
        // Risky-stem verifier (see riskyRootCandidates).
        if (isset($config['stem_verifier'])) {
            $this->stemVerifier = \Closure::fromCallable($config['stem_verifier']);
        }

        // Error contract: configuration errors throw actionable
        // messages at the earliest possible moment. A wrong path used to be
        // the WORST failure mode: PDO silently creates an empty SQLite file,
        // and the mistake surfaced later as "no such table: field_stats"
        // deep inside a search. Probe first (file), then verify the schema
        // marker table after opening.
        if ($dbPath !== ':memory:' && !is_file($dbPath)) {
            throw new \InvalidArgumentException(
                "MultiSearch index not found: '$dbPath' (build one with MultiBuilder or scripts/build-index.php)"
            );
        }
        // TRUE read-only open. Earlier the open asked SQLite for
        // journal_mode=WAL every time — which is not a connection setting
        // but a persistent FILE CONVERSION: it wrote to the "read-only"
        // index, demanded write permission (a read-only mount threw on
        // open), and left -wal/-shm sidecars beside an artifact that is
        // never written. WAL also bought nothing here: concurrent READERS
        // never block each other in any journal mode — WAL exists for
        // readers-during-WRITER, and the searcher's index has no writer.
        // SQLITE_OPEN_READONLY makes the no-writes contract the OS's
        // problem instead of a promise. Fallback: a Builder-built index is
        // WAL-mode BY DESIGN (Builder keeps WAL so readers stay live during
        // incremental updates — see MultiBuilder), and a WAL file needs
        // writable -shm access that a read-only open may lack — reopen
        // read-write with query_only. Scripted artifacts (build-index.php,
        // build-oewn.php) are rollback-journal files and never take this path.
        // Earlier:
        // $this->db = new \PDO("sqlite:$dbPath");
        // ... $this->db->exec("PRAGMA journal_mode = WAL");
        // ... $this->db->exec("PRAGMA synchronous  = OFF");
        // ... $this->db->exec("PRAGMA query_only   = true");
        try {
            $this->db = new \PDO("sqlite:$dbPath", null, null,
                [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
        } catch (\PDOException $e) {
            $this->db = new \PDO("sqlite:$dbPath");
            $this->db->exec("PRAGMA query_only = true");
        }
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $isIndex = $this->db->query(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'field_stats'"
        )->fetchColumn();
        if (!$isIndex) {
            throw new \InvalidArgumentException(
                "'$dbPath' is not a MultiSearch index (no field_stats table)"
            );
        }
        $this->db->exec("PRAGMA mmap_size    = 2147483648"); // 2 GB — lets OS cache DB pages
        $this->db->exec("PRAGMA cache_size   = -128000");    // 128 MB SQLite page cache
        $this->db->exec("PRAGMA temp_store   = MEMORY");
        // Wait briefly instead of failing if a Builder is mid-write
        // on this index (readers only block during brief checkpoint/lock
        // windows; the demo's rebuilt-from-scratch index never has one).
        $this->db->exec("PRAGMA busy_timeout = 2000");

        // Fold query terms iff the index was built folded. The index knows
        // how it was tokenized (meta.tokenizer_name); making the searcher
        // read it closes the build/query drift the tokenization contract
        // was written against. An explicit config bool overrides (for
        // indexes built with a custom tokenizer and no meta row).
        if (isset($config['fold_diacritics'])) {
            $this->foldDiacritics = (bool)$config['fold_diacritics'];
        } else {
            $this->foldDiacritics = $this->readMeta('tokenizer_name') === self::TOKENIZER_FOLDED;
        }
    }

    /** One value from the index's meta table, or null (no table / no row). */
    private function readMeta(string $key): ?string
    {
        try {
            $stmt = $this->db->prepare("SELECT value FROM meta WHERE key = ?");
            $stmt->execute([$key]);
            $v = $stmt->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (\PDOException $e) {
            return null;   // minimal/foreign index without a meta table
        }
    }

    /** Whether query terms are diacritic-folded (index-driven, see ctor). */
    public function foldsDiacritics(): bool
    {
        return $this->foldDiacritics;
    }

    // There is deliberately no setter for k1/b. An earlier setBM25Params()
    // was the one mutable configuration path in an otherwise immutable design
    // (constructor profile + per-search overrides); it went, and k1/b joined
    // the ranking profile: ['ranking' => ['k1' => ..., 'b' => ...]].

    /** Reject unknown ranking keys — tuning typos must not silently no-op. */
    private static function checkRankingKeys(array $ranking): void
    {
        $bad = array_diff_key($ranking, self::RANKING_DEFAULTS);
        if (!empty($bad)) {
            throw new \InvalidArgumentException(
                'Unknown ranking keys: ' . implode(', ', array_keys($bad))
                . ' (valid: ' . implode(', ', array_keys(self::RANKING_DEFAULTS)) . ')'
            );
        }
    }

    // =========================================================================
    // Tokenization — see the contract note near TOKENIZER_DEFAULT
    // =========================================================================

    /**
     * Canonical text → tokens, used at BUILD time (scripts/build-index.php,
     * Builder::addText()). Lowercase, fold diacritics if $fold,
     * strip apostrophes, punctuation to spaces, split on whitespace:
     * don't → dont; Coca-Cola → coca, cola; São → sao (folded) / são (not).
     * $fold selects the TOKENIZER_FOLDED vocabulary shape — the index and
     * every query against it must agree on it.
     * This used to live as a private copy inside the build scripts; it moved
     * here so build and query share ONE implementation (see the contract
     * note near TOKENIZER_DEFAULT for what the drift cost).
     */
    public static function tokenize(string $text, bool $fold = false): array
    {
        $text  = mb_strtolower($text);
        if ($fold) $text = self::foldDiacritics($text);      // café → cafe
        $text  = preg_replace("/'/u", '', $text);            // don't → dont
        $text  = preg_replace('#[[:punct:]]#u', ' ', $text); // coca-cola → coca cola
        $parts = preg_split('/\s+/u', trim($text));
        return array_values(array_filter($parts, fn($v) => $v !== ''));
    }

    /**
     * Strip diacritics from Latin text: é→e, ñ→n, ø→o, ł→l, ß→ss,
     * æ→ae, þ→th. Two steps: (1) a precomposed-character table (Latin-1
     * Supplement, Latin Extended-A/B, Latin Extended Additional — which
     * covers Vietnamese's stacked tones), generated from Unicode NFKD
     * decompositions plus the handful of letters NFKD leaves alone (ø ł đ ß
     * æ œ þ ð ı); (2) drop U+0300–U+036F combining marks, so text that
     * arrives DECOMPOSED (e + U+0301) folds identically. Deliberately NOT
     * \p{M} — that would strip Devanagari/Thai/Arabic vowel signs, which
     * are letters' worth of meaning, not decoration. Greek, Cyrillic, CJK
     * pass through unchanged.
     *
     * Why hand-rolled: this runtime has no intl extension (no Normalizer /
     * Transliterator), and iconv //TRANSLIT here returns "Cr`eme S~ao".
     * Case-preserving; tokenize() lowercases first. ASCII fast path.
     *
     * Known trade-off: folding conflates real distinctions in some
     * languages (año/ano, Swedish å≠a, German ü vs ue). For an English
     * corpus with imported names the recall win dominates; corpora where
     * it doesn't leave 'fold_diacritics' off.
     */
    public static function foldDiacritics(string $text): string
    {
        if (!preg_match('/[^\x00-\x7F]/', $text)) return $text;
        $text = strtr($text, self::FOLD_MAP);
        return preg_replace('/[\x{0300}-\x{036F}]+/u', '', $text);
    }

    /** Precomposed Latin letter → ASCII base. See foldDiacritics(). */
    private const FOLD_MAP = [
        'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'AE', 'Ç'=>'C', 'È'=>'E', 'É'=>'E', 'Ê'=>'E', 'Ë'=>'E',
        'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ð'=>'D', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O',
        'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'TH', 'ß'=>'ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a',
        'å'=>'a', 'æ'=>'ae', 'ç'=>'c', 'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'d',
        'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ü'=>'u', 'ý'=>'y',
        'þ'=>'th', 'ÿ'=>'y', 'Ā'=>'A', 'ā'=>'a', 'Ă'=>'A', 'ă'=>'a', 'Ą'=>'A', 'ą'=>'a', 'Ć'=>'C', 'ć'=>'c', 'Ĉ'=>'C', 'ĉ'=>'c',
        'Ċ'=>'C', 'ċ'=>'c', 'Č'=>'C', 'č'=>'c', 'Ď'=>'D', 'ď'=>'d', 'Đ'=>'D', 'đ'=>'d', 'Ē'=>'E', 'ē'=>'e', 'Ĕ'=>'E', 'ĕ'=>'e',
        'Ė'=>'E', 'ė'=>'e', 'Ę'=>'E', 'ę'=>'e', 'Ě'=>'E', 'ě'=>'e', 'Ĝ'=>'G', 'ĝ'=>'g', 'Ğ'=>'G', 'ğ'=>'g', 'Ġ'=>'G', 'ġ'=>'g',
        'Ģ'=>'G', 'ģ'=>'g', 'Ĥ'=>'H', 'ĥ'=>'h', 'Ħ'=>'H', 'ħ'=>'h', 'Ĩ'=>'I', 'ĩ'=>'i', 'Ī'=>'I', 'ī'=>'i', 'Ĭ'=>'I', 'ĭ'=>'i',
        'Į'=>'I', 'į'=>'i', 'İ'=>'I', 'ı'=>'i', 'Ĳ'=>'IJ', 'ĳ'=>'ij', 'Ĵ'=>'J', 'ĵ'=>'j', 'Ķ'=>'K', 'ķ'=>'k', 'Ĺ'=>'L', 'ĺ'=>'l',
        'Ļ'=>'L', 'ļ'=>'l', 'Ľ'=>'L', 'ľ'=>'l', 'Ł'=>'L', 'ł'=>'l', 'Ń'=>'N', 'ń'=>'n', 'Ņ'=>'N', 'ņ'=>'n', 'Ň'=>'N', 'ň'=>'n',
        'Ŋ'=>'N', 'ŋ'=>'n', 'Ō'=>'O', 'ō'=>'o', 'Ŏ'=>'O', 'ŏ'=>'o', 'Ő'=>'O', 'ő'=>'o', 'Œ'=>'OE', 'œ'=>'oe', 'Ŕ'=>'R', 'ŕ'=>'r',
        'Ŗ'=>'R', 'ŗ'=>'r', 'Ř'=>'R', 'ř'=>'r', 'Ś'=>'S', 'ś'=>'s', 'Ŝ'=>'S', 'ŝ'=>'s', 'Ş'=>'S', 'ş'=>'s', 'Š'=>'S', 'š'=>'s',
        'Ţ'=>'T', 'ţ'=>'t', 'Ť'=>'T', 'ť'=>'t', 'Ŧ'=>'T', 'ŧ'=>'t', 'Ũ'=>'U', 'ũ'=>'u', 'Ū'=>'U', 'ū'=>'u', 'Ŭ'=>'U', 'ŭ'=>'u',
        'Ů'=>'U', 'ů'=>'u', 'Ű'=>'U', 'ű'=>'u', 'Ų'=>'U', 'ų'=>'u', 'Ŵ'=>'W', 'ŵ'=>'w', 'Ŷ'=>'Y', 'ŷ'=>'y', 'Ÿ'=>'Y', 'Ź'=>'Z',
        'ź'=>'z', 'Ż'=>'Z', 'ż'=>'z', 'Ž'=>'Z', 'ž'=>'z', 'ſ'=>'s', 'ƀ'=>'b', 'Ơ'=>'O', 'ơ'=>'o', 'Ư'=>'U', 'ư'=>'u', 'Ǆ'=>'DZ',
        'ǅ'=>'Dz', 'ǆ'=>'dz', 'Ǉ'=>'LJ', 'ǈ'=>'Lj', 'ǉ'=>'lj', 'Ǌ'=>'NJ', 'ǋ'=>'Nj', 'ǌ'=>'nj', 'Ǎ'=>'A', 'ǎ'=>'a', 'Ǐ'=>'I', 'ǐ'=>'i',
        'Ǒ'=>'O', 'ǒ'=>'o', 'Ǔ'=>'U', 'ǔ'=>'u', 'Ǖ'=>'U', 'ǖ'=>'u', 'Ǘ'=>'U', 'ǘ'=>'u', 'Ǚ'=>'U', 'ǚ'=>'u', 'Ǜ'=>'U', 'ǜ'=>'u',
        'Ǟ'=>'A', 'ǟ'=>'a', 'Ǡ'=>'A', 'ǡ'=>'a', 'Ǧ'=>'G', 'ǧ'=>'g', 'Ǩ'=>'K', 'ǩ'=>'k', 'Ǫ'=>'O', 'ǫ'=>'o', 'Ǭ'=>'O', 'ǭ'=>'o',
        'ǰ'=>'j', 'Ǳ'=>'DZ', 'ǲ'=>'Dz', 'ǳ'=>'dz', 'Ǵ'=>'G', 'ǵ'=>'g', 'Ǹ'=>'N', 'ǹ'=>'n', 'Ǻ'=>'A', 'ǻ'=>'a', 'Ǿ'=>'O', 'ǿ'=>'o',
        'Ȁ'=>'A', 'ȁ'=>'a', 'Ȃ'=>'A', 'ȃ'=>'a', 'Ȅ'=>'E', 'ȅ'=>'e', 'Ȇ'=>'E', 'ȇ'=>'e', 'Ȉ'=>'I', 'ȉ'=>'i', 'Ȋ'=>'I', 'ȋ'=>'i',
        'Ȍ'=>'O', 'ȍ'=>'o', 'Ȏ'=>'O', 'ȏ'=>'o', 'Ȑ'=>'R', 'ȑ'=>'r', 'Ȓ'=>'R', 'ȓ'=>'r', 'Ȕ'=>'U', 'ȕ'=>'u', 'Ȗ'=>'U', 'ȗ'=>'u',
        'Ș'=>'S', 'ș'=>'s', 'Ț'=>'T', 'ț'=>'t', 'Ȟ'=>'H', 'ȟ'=>'h', 'Ȧ'=>'A', 'ȧ'=>'a', 'Ȩ'=>'E', 'ȩ'=>'e', 'Ȫ'=>'O', 'ȫ'=>'o',
        'Ȭ'=>'O', 'ȭ'=>'o', 'Ȯ'=>'O', 'ȯ'=>'o', 'Ȱ'=>'O', 'ȱ'=>'o', 'Ȳ'=>'Y', 'ȳ'=>'y', 'ɨ'=>'i', 'Ḁ'=>'A', 'ḁ'=>'a', 'Ḃ'=>'B',
        'ḃ'=>'b', 'Ḅ'=>'B', 'ḅ'=>'b', 'Ḇ'=>'B', 'ḇ'=>'b', 'Ḉ'=>'C', 'ḉ'=>'c', 'Ḋ'=>'D', 'ḋ'=>'d', 'Ḍ'=>'D', 'ḍ'=>'d', 'Ḏ'=>'D',
        'ḏ'=>'d', 'Ḑ'=>'D', 'ḑ'=>'d', 'Ḓ'=>'D', 'ḓ'=>'d', 'Ḕ'=>'E', 'ḕ'=>'e', 'Ḗ'=>'E', 'ḗ'=>'e', 'Ḙ'=>'E', 'ḙ'=>'e', 'Ḛ'=>'E',
        'ḛ'=>'e', 'Ḝ'=>'E', 'ḝ'=>'e', 'Ḟ'=>'F', 'ḟ'=>'f', 'Ḡ'=>'G', 'ḡ'=>'g', 'Ḣ'=>'H', 'ḣ'=>'h', 'Ḥ'=>'H', 'ḥ'=>'h', 'Ḧ'=>'H',
        'ḧ'=>'h', 'Ḩ'=>'H', 'ḩ'=>'h', 'Ḫ'=>'H', 'ḫ'=>'h', 'Ḭ'=>'I', 'ḭ'=>'i', 'Ḯ'=>'I', 'ḯ'=>'i', 'Ḱ'=>'K', 'ḱ'=>'k', 'Ḳ'=>'K',
        'ḳ'=>'k', 'Ḵ'=>'K', 'ḵ'=>'k', 'Ḷ'=>'L', 'ḷ'=>'l', 'Ḹ'=>'L', 'ḹ'=>'l', 'Ḻ'=>'L', 'ḻ'=>'l', 'Ḽ'=>'L', 'ḽ'=>'l', 'Ḿ'=>'M',
        'ḿ'=>'m', 'Ṁ'=>'M', 'ṁ'=>'m', 'Ṃ'=>'M', 'ṃ'=>'m', 'Ṅ'=>'N', 'ṅ'=>'n', 'Ṇ'=>'N', 'ṇ'=>'n', 'Ṉ'=>'N', 'ṉ'=>'n', 'Ṋ'=>'N',
        'ṋ'=>'n', 'Ṍ'=>'O', 'ṍ'=>'o', 'Ṏ'=>'O', 'ṏ'=>'o', 'Ṑ'=>'O', 'ṑ'=>'o', 'Ṓ'=>'O', 'ṓ'=>'o', 'Ṕ'=>'P', 'ṕ'=>'p', 'Ṗ'=>'P',
        'ṗ'=>'p', 'Ṙ'=>'R', 'ṙ'=>'r', 'Ṛ'=>'R', 'ṛ'=>'r', 'Ṝ'=>'R', 'ṝ'=>'r', 'Ṟ'=>'R', 'ṟ'=>'r', 'Ṡ'=>'S', 'ṡ'=>'s', 'Ṣ'=>'S',
        'ṣ'=>'s', 'Ṥ'=>'S', 'ṥ'=>'s', 'Ṧ'=>'S', 'ṧ'=>'s', 'Ṩ'=>'S', 'ṩ'=>'s', 'Ṫ'=>'T', 'ṫ'=>'t', 'Ṭ'=>'T', 'ṭ'=>'t', 'Ṯ'=>'T',
        'ṯ'=>'t', 'Ṱ'=>'T', 'ṱ'=>'t', 'Ṳ'=>'U', 'ṳ'=>'u', 'Ṵ'=>'U', 'ṵ'=>'u', 'Ṷ'=>'U', 'ṷ'=>'u', 'Ṹ'=>'U', 'ṹ'=>'u', 'Ṻ'=>'U',
        'ṻ'=>'u', 'Ṽ'=>'V', 'ṽ'=>'v', 'Ṿ'=>'V', 'ṿ'=>'v', 'Ẁ'=>'W', 'ẁ'=>'w', 'Ẃ'=>'W', 'ẃ'=>'w', 'Ẅ'=>'W', 'ẅ'=>'w', 'Ẇ'=>'W',
        'ẇ'=>'w', 'Ẉ'=>'W', 'ẉ'=>'w', 'Ẋ'=>'X', 'ẋ'=>'x', 'Ẍ'=>'X', 'ẍ'=>'x', 'Ẏ'=>'Y', 'ẏ'=>'y', 'Ẑ'=>'Z', 'ẑ'=>'z', 'Ẓ'=>'Z',
        'ẓ'=>'z', 'Ẕ'=>'Z', 'ẕ'=>'z', 'ẖ'=>'h', 'ẗ'=>'t', 'ẘ'=>'w', 'ẙ'=>'y', 'ẛ'=>'s', 'ẞ'=>'SS', 'Ạ'=>'A', 'ạ'=>'a', 'Ả'=>'A',
        'ả'=>'a', 'Ấ'=>'A', 'ấ'=>'a', 'Ầ'=>'A', 'ầ'=>'a', 'Ẩ'=>'A', 'ẩ'=>'a', 'Ẫ'=>'A', 'ẫ'=>'a', 'Ậ'=>'A', 'ậ'=>'a', 'Ắ'=>'A',
        'ắ'=>'a', 'Ằ'=>'A', 'ằ'=>'a', 'Ẳ'=>'A', 'ẳ'=>'a', 'Ẵ'=>'A', 'ẵ'=>'a', 'Ặ'=>'A', 'ặ'=>'a', 'Ẹ'=>'E', 'ẹ'=>'e', 'Ẻ'=>'E',
        'ẻ'=>'e', 'Ẽ'=>'E', 'ẽ'=>'e', 'Ế'=>'E', 'ế'=>'e', 'Ề'=>'E', 'ề'=>'e', 'Ể'=>'E', 'ể'=>'e', 'Ễ'=>'E', 'ễ'=>'e', 'Ệ'=>'E',
        'ệ'=>'e', 'Ỉ'=>'I', 'ỉ'=>'i', 'Ị'=>'I', 'ị'=>'i', 'Ọ'=>'O', 'ọ'=>'o', 'Ỏ'=>'O', 'ỏ'=>'o', 'Ố'=>'O', 'ố'=>'o', 'Ồ'=>'O',
        'ồ'=>'o', 'Ổ'=>'O', 'ổ'=>'o', 'Ỗ'=>'O', 'ỗ'=>'o', 'Ộ'=>'O', 'ộ'=>'o', 'Ớ'=>'O', 'ớ'=>'o', 'Ờ'=>'O', 'ờ'=>'o', 'Ở'=>'O',
        'ở'=>'o', 'Ỡ'=>'O', 'ỡ'=>'o', 'Ợ'=>'O', 'ợ'=>'o', 'Ụ'=>'U', 'ụ'=>'u', 'Ủ'=>'U', 'ủ'=>'u', 'Ứ'=>'U', 'ứ'=>'u', 'Ừ'=>'U',
        'ừ'=>'u', 'Ử'=>'U', 'ử'=>'u', 'Ữ'=>'U', 'ữ'=>'u', 'Ự'=>'U', 'ự'=>'u', 'Ỳ'=>'Y', 'ỳ'=>'y', 'Ỵ'=>'Y', 'ỵ'=>'y', 'Ỷ'=>'Y',
        'ỷ'=>'y', 'Ỹ'=>'Y', 'ỹ'=>'y'
    ];

    /**
     * Query-term normalization through the configured hook, or tokenize()
     * itself — the SAME rules as the build side, so a query term can only
     * produce tokens in the index's vocabulary shape. May return several
     * tokens ("coca-cola" → 2) or none (pure punctuation).
     */
    private function normalizeQueryTerm(string $term): array
    {
        return $this->termNormalizer !== null
            ? ($this->termNormalizer)($term)
            : self::tokenize($term, $this->foldDiacritics);
    }

    // =========================================================================
    // Position blobs
    // =========================================================================
    // Token positions per (term, doc, field) ride in a nullable `pos` BLOB
    // column on the postings rows, encoded as delta + unsigned LEB128 varints
    // (first value absolute, i.e. delta from 0). The corpus averages ~1.8
    // occurrences per posting row, so most blobs are 1-2 bytes — measured
    // +15.8% index size on the Wikipedia corpus, vs the rejected
    // one-row-per-occurrence design's ~10x.
    // Positions are 0-based token indexes in tokenize() order; they are only
    // ever decoded for PHRASE VERIFICATION on pruned candidates (never on the
    // broad postings fetch — that discipline is what keeps memory flat).
    // Scoring does not read positions: ranking is bit-identical for every
    // query without quoted phrases.

    /** Encode ascending 0-based positions as delta-LEB128. Public: the build
     *  pipeline (MultiBuilder, scripts/build-index.php) writes blobs with it. */
    public static function encodePositions(array $positions): string
    {
        $out  = '';
        $prev = 0;
        foreach ($positions as $p) {
            $delta = $p - $prev;
            $prev  = $p;
            do {
                $byte   = $delta & 0x7F;
                $delta >>= 7;
                $out   .= chr($delta > 0 ? $byte | 0x80 : $byte);
            } while ($delta > 0);
        }
        return $out;
    }

    /** Decode a position blob; null/'' (position-less rows) decode to []. */
    public static function decodePositions(?string $blob): array
    {
        if ($blob === null || $blob === '') return [];
        $out = []; $prev = 0; $cur = 0; $shift = 0;
        for ($i = 0, $n = strlen($blob); $i < $n; $i++) {
            $b    = ord($blob[$i]);
            $cur |= ($b & 0x7F) << $shift;
            if ($b & 0x80) { $shift += 7; continue; }
            $prev += $cur;
            $out[] = $prev;
            $cur = 0; $shift = 0;
        }
        return $out;
    }

    // Lazy per-field introspection: does postings_<field> carry a pos column?
    // Same idiom as documentsColumns() — optional schema is detected, never
    // configured: positions are a build option (build-index.php
    // --no-positions, Builder 'positions' => false), and the searcher serves
    // either kind without being told which.
    private array $positionsAvail = [];

    private function positionsAvailable(string $field): bool
    {
        if (!isset($this->positionsAvail[$field])) {
            try {
                $cols = $this->db->query("PRAGMA table_info(postings_$field)")
                                 ->fetchAll(\PDO::FETCH_COLUMN, 1);
            } catch (\Exception $e) {
                $cols = [];
            }
            $this->positionsAvail[$field] = in_array('pos', $cols, true);
        }
        return $this->positionsAvail[$field];
    }

    // =========================================================================
    // Stemming — suffix-based root word extraction
    // =========================================================================
    // Adapted from iSearch's rootWords() approach. Strips common English
    // suffixes (-s, -es, -ing, -ed, -ful, -ies, -ves, -oes) and adds the
    // root form alongside the original.
    //
    // We tried Porter stemming but it was too aggressive for this use case:
    // "universe" → "univers" lost exact matches against the index. This
    // simpler approach handles the most common inflections without
    // over-stemming. The original term is always kept so exact matches
    // still work.

    // The rule set covers the LOW-RISK inflection types (rules whose outputs
    // are either correct or non-words that match nothing). Deliberately NOT
    // in this blind set: -er/-est, -ly, -ous, -ity — those frequently produce
    // real but unrelated index terms (summer→sum, early→ear, corner→corn).
    // They live in riskyRootCandidates() and are only used when a dictionary
    // verifier confirms the pair. Generation here stays blind and static:
    // bogus variants are harmless (they match nothing), and verifying
    // against the index couldn't catch real-word collisions anyway.
    public static function rootWords(array $terms): array
    {
        // Irregular plurals — no suffix rule can reach these. Checked
        // before the length guard ("men" is only 3 chars).
        static $IRREGULARS = [
            'children' => 'child', 'women' => 'woman', 'men' => 'man',
            'people'   => 'person', 'feet' => 'foot', 'teeth' => 'tooth',
            'mice'     => 'mouse', 'geese' => 'goose',
        ];
        // Doubled-consonant undo: running → runn → run, stopped → stopp → stop.
        // Earlier rules produced only "runn"/"runne", which match nothing.
        $undouble = function (string $root) use (&$result): void {
            if (mb_strlen($root) >= 3
                && mb_substr($root, -1) === mb_substr($root, -2, 1)
                && preg_match('/[bcdfghjklmnpqrstvwz]/', mb_substr($root, -1))
            ) {
                $result[] = mb_substr($root, 0, -1);
            }
        };

        $result = $terms;
        foreach ($terms as $word) {
            if (isset($IRREGULARS[$word])) {
                $result[] = $IRREGULARS[$word];
                continue;
            }
            if (strlen($word) <= 4) continue;
            // -iness/-ness must precede the -s family below, which would
            // otherwise strip a lone 's' (darkness → "darknes").
            if (mb_substr($word, -5) === 'iness') {
                $result[] = mb_substr($word, 0, -5) . 'y';   // happiness → happy
            } elseif (mb_substr($word, -4) === 'ness') {
                $result[] = mb_substr($word, 0, -4);         // darkness → dark
            } elseif (mb_substr($word, -3) === 'ies') {
                $result[] = mb_substr($word, 0, -3) . 'y';
            } elseif (mb_substr($word, -3) === 'ves') {
                $result[] = mb_substr($word, 0, -3) . 'f';
                $result[] = mb_substr($word, 0, -3) . 'fe';
            } elseif (mb_substr($word, -3) === 'oes') {
                $result[] = mb_substr($word, 0, -2);
            } elseif (mb_substr($word, -4) === 'sses') {
                $result[] = mb_substr($word, 0, -3);
                $result[] = mb_substr($word, 0, -2);
            } elseif (mb_substr($word, -2) === 'es') {
                $result[] = mb_substr($word, 0, -1);
                $result[] = mb_substr($word, 0, -2);
            } elseif (mb_substr($word, -1) === 's') {
                $result[] = mb_substr($word, 0, -1);
            } elseif (mb_substr($word, -3) === 'ing') {
                $root = mb_substr($word, 0, -3);
                $result[] = $root;
                $result[] = $root . 'e'; // hiking → hike
                $undouble($root);        // running → run
            } elseif (mb_substr($word, -2) === 'ed') {
                $root = mb_substr($word, 0, -2);
                $result[] = $root;
                $result[] = mb_substr($word, 0, -1); // hated → hate
                $undouble($root);        // stopped → stop
            } elseif (mb_substr($word, -4) === 'iful') {
                $result[] = mb_substr($word, 0, -4) . 'y';
            } elseif (mb_substr($word, -3) === 'ful') {
                $result[] = mb_substr($word, 0, -3);
                // also the y-form (cheerful → cheery), which the original
                // iSearch rootWords emitted and an early copy here had dropped.
                $result[] = mb_substr($word, 0, -3) . 'y';
            // Derivational suffixes (all end in letters no earlier rule
            // matches, so placement after the inflectional rules is safe).
            } elseif (mb_substr($word, -5) === 'ation') {
                $result[] = mb_substr($word, 0, -5);          // information → inform
                $result[] = mb_substr($word, 0, -5) . 'ate';  // creation → create
            } elseif (mb_substr($word, -3) === 'ion') {
                $result[] = mb_substr($word, 0, -3);          // action → act
                $result[] = mb_substr($word, 0, -3) . 'e';    // confusion → confuse
            } elseif (mb_substr($word, -4) === 'ment' && mb_strlen($word) >= 7) {
                // length guard: movement → move, but moment must NOT → "mo"
                $result[] = mb_substr($word, 0, -4);          // government → govern
            } elseif ((mb_substr($word, -3) === 'ize' || mb_substr($word, -3) === 'ise')
                      && mb_strlen($word) >= 7) {
                // length guard: modernize → modern, but prize must NOT → "pr"
                $result[] = mb_substr($word, 0, -3);
            } elseif (mb_substr($word, -4) === 'ical') {
                $result[] = mb_substr($word, 0, -2);          // historical → historic
                $result[] = mb_substr($word, 0, -4) . 'y';    // historical → history
            } elseif (mb_substr($word, -2) === 'al' && mb_strlen($word) >= 7) {
                $result[] = mb_substr($word, 0, -2);          // national → nation
            }
        }
        return array_values(array_unique(array_filter($result, fn($w) => strlen($w) > 1)));
    }

    /**
     * The RISKY stem rules — suffixes whose stripped roots are often
     * real but unrelated words (summer->sum, corner->corn, early->ear), which
     * is why they were excluded from rootWords() (see its header). Their
     * outputs are ONLY usable after dictionary verification: the Searcher
     * checks each candidate through the profile's 'stem_verifier' (OEWN
     * derivational links — see OewnSynonyms::derivationVerifier) and keeps
     * verified pairs (quickly->quick, dangerous->danger, equality->equal).
     * Generation is deliberately generous — verification is the precision
     * filter, so a bogus candidate costs one cached lookup, nothing more.
     */
    public static function riskyRootCandidates(string $word): array
    {
        $out = [];
        $len = mb_strlen($word);
        if ($len <= 4) return $out;
        $undouble = function (string $root) use (&$out): void {
            if (mb_strlen($root) >= 3
                && mb_substr($root, -1) === mb_substr($root, -2, 1)
                && preg_match('/[bcdfghjklmnpqrstvwz]/', mb_substr($root, -1))
            ) {
                $out[] = mb_substr($root, 0, -1);
            }
        };
        if (mb_substr($word, -4) === 'lity' && $len >= 7) {
            $out[] = mb_substr($word, 0, -4) . 'le';           // ability → able
        }
        if (mb_substr($word, -3) === 'ity' && $len >= 6) {
            $out[] = mb_substr($word, 0, -3);                  // equality → equal
            $out[] = mb_substr($word, 0, -3) . 'e';            // purity → pure
        }
        if (mb_substr($word, -3) === 'ily' && $len >= 6) {
            $out[] = mb_substr($word, 0, -3) . 'y';            // happily → happy
        }
        if (mb_substr($word, -2) === 'ly' && $len >= 5) {
            $out[] = mb_substr($word, 0, -2);                  // quickly → quick
        }
        if (mb_substr($word, -3) === 'ous' && $len >= 6) {
            $out[] = mb_substr($word, 0, -3);                  // dangerous → danger
            $out[] = mb_substr($word, 0, -3) . 'e';            // famous → fame
        }
        if (mb_substr($word, -3) === 'est' && $len >= 6) {
            $out[] = mb_substr($word, 0, -3);                  // fastest → fast
            $out[] = mb_substr($word, 0, -2);                  // largest → large
            $undouble(mb_substr($word, 0, -3));                // biggest → big
        } elseif (mb_substr($word, -2) === 'er' && $len >= 5) {
            $out[] = mb_substr($word, 0, -2);                  // player → play
            $out[] = mb_substr($word, 0, -1);                  // larger → large
            $undouble(mb_substr($word, 0, -2));                // runner → run
        }
        return array_values(array_unique(array_filter($out, fn($w) => mb_strlen($w) > 2)));
    }

    // =========================================================================
    // Morphological expansion — bidirectional suffix matching
    // =========================================================================
    // rootWords() strips suffixes ("tunnelling" → "tunnel"). This method does
    // the INVERSE: adds common suffixes ("tunnel" → "tunnels", "tunnelling",
    // "tunneled") and checks if each variant exists in the index.
    //
    // Why: searching "tunnel" doesn't find "Quantum tunnelling" because our
    // simple stemmer can strip -ling from "tunnelling" → "tunnel", but can't
    // ADD -ling to "tunnel". Fuzzy matching also misses it because the edit
    // distance is too large (4 characters). Morphological expansion fills this gap.
    //
    // Only returns variants that actually exist in the unique_terms table, so
    // we never add phantom terms that waste query time.
    //
    // Inspired by Hunspell's affix expansion and Elasticsearch's analysis chain
    // (stemmer + word forms). Our approach is simpler: fixed suffix list checked
    // against the index, no dictionary or rule files needed.

    // Private on purpose (it was public for a long time, by accident, and
    // nothing outside search() ever needed it). The public expansion surface
    // is the static PURE helpers
    // (tokenize, rootWords, riskyRootCandidates, the position codec): usable
    // without an index, shared with the build pipeline. Instance methods that
    // touch the index or profile are engine internals. (This is the class's
    // static/instance rule in one sentence — statics are pure functions,
    // instance methods do I/O.)
    private function morphExpand(array $terms): array
    {
        if (empty($terms)) return [];

        // Common English suffixes for generating morphological variants.
        // Ordered roughly by frequency/importance.
        $suffixes = [
            's', 'es', 'ed', 'd', 'ing',
            'er', 'ers', 'est',
            'ly', 'ful', 'ness', 'ment', 'tion', 'sion',
            'ling', 'lling',  // tunnel → tunnelling
            'al', 'ial', 'ual',
            'ous', 'ious',
            'ity', 'ety',
            'ize', 'ise',     // American/British
        ];

        // Also try replacing trailing 'e' with suffix (e.g., "make" → "making")
        $eReplaceSuffixes = ['ing', 'ed', 'er', 'ers', 'ation'];

        $candidates = [];
        foreach ($terms as $word) {
            if (mb_strlen($word) < 3) continue;
            // Add suffixes directly
            foreach ($suffixes as $sfx) {
                $candidates[] = $word . $sfx;
            }
            // If word ends in 'e', try replacing 'e' with suffix
            if (mb_substr($word, -1) === 'e') {
                $base = mb_substr($word, 0, -1);
                foreach ($eReplaceSuffixes as $sfx) {
                    $candidates[] = $base . $sfx;
                }
            }
            // If word ends in consonant, try doubling last char + suffix
            // (e.g., "run" → "running", "tunnel" → "tunnelling")
            $lastChar = mb_substr($word, -1);
            if (preg_match('/[bcdfghjklmnpqrstvwxyz]/i', $lastChar)) {
                $candidates[] = $word . $lastChar . 'ing';
                $candidates[] = $word . $lastChar . 'ed';
                $candidates[] = $word . $lastChar . 'er';
            }
        }

        $candidates = array_values(array_unique(array_diff($candidates, $terms)));
        if (empty($candidates)) return [];

        // Check which candidates actually exist in the index
        $verified = [];
        foreach (array_chunk($candidates, self::BATCH_SIZE) as $batch) {
            $ph = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $this->db->prepare("SELECT term FROM unique_terms WHERE term IN ($ph)");
            $stmt->execute($batch);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $t) {
                $verified[] = $t;
            }
        }
        return $verified;
    }

    // =========================================================================
    // Public search API
    // =========================================================================

    public function search(string $query, array $options = []): array
    {
        $algo       = $options['algo']       ?? 'bm25';
        // Error contract: an unknown algo used to fall through
        // scoreDoc()'s switch, score every document 0, and return an EMPTY
        // result set that looked exactly like "no matches" — the silent
        // sibling of checkRankingKeys()'s loud typo protection. Same rule
        // now applies: configuration errors throw, empty results never do.
        if (!in_array($algo, self::ALGOS, true)) {
            throw new \InvalidArgumentException(
                "Unknown algo '$algo' (valid: " . implode(', ', self::ALGOS) . ')'
            );
        }
        $requestedAlgo = $algo;   // kept for the diagnostics channel
        $confidence = max(0, min(100, (int)($options['confidence'] ?? 100)));
        $page       = max(1, (int)($options['page']     ?? 1));
        $perPage    = max(1, (int)($options['per_page'] ?? 20));
        $stopwords  = $options['stopwords'] ?? [];
        $doStem     = (bool)($options['stemming']  ?? false);
        $synonyms   = $options['synonyms']  ?? [];
        // Derivational expansion groups, same shape as synonyms
        // ([[word, related...], ...]); see OewnSynonyms::derivationsFor().
        $derivGroups = $options['derivations'] ?? [];
        // Risky-stem verifier — per-search override falls back to config.
        $stemVerifier = array_key_exists('stem_verifier', $options)
            ? ($options['stem_verifier'] !== null ? \Closure::fromCallable($options['stem_verifier']) : null)
            : $this->stemVerifier;
        // Two-phase retrieval: fetch light fields first, heavy_fields only for
        // survivors. Faster (~2x) but can miss docs matching only in a heavy field.
        // Default OFF to prioritize completeness over speed.
        $twoPhase   = (bool)($options['two_phase'] ?? false);

        // Resolve the corpus profile — per-search override falls back to the
        // constructor config, which falls back to neutral defaults.
        // array_key_exists (not ??) for title_field so an explicit null override
        // can disable the bonus for one search.
        $titleField  = array_key_exists('title_field', $options) ? $options['title_field'] : $this->titleField;
        $heavyFields = $options['heavy_fields'] ?? $this->heavyFields;
        $fieldBMap   = $options['field_b'] ?? $this->fieldB;
        $maxWild     = (int)($options['max_wildcard_expansions'] ?? $this->maxWildcardExpansions);
        $maxFuzzy    = (int)($options['max_fuzzy_per_term'] ?? $this->maxFuzzyPerTerm);
        $hfCutoff    = (float)($options['highfreq_cutoff'] ?? $this->highfreqCutoff);
        $candLimit   = (int)($options['candidate_limit'] ?? $this->candidateLimit);
        $p1Limit     = (int)($options['phase1_limit'] ?? $this->phase1Limit);

        // Resolve the ranking profile — per-search overrides on top of
        // the instance profile (which is defaults + constructor overrides).
        // See RANKING_DEFAULTS for what each knob does.
        $rk = $this->ranking;
        if (isset($options['ranking'])) {
            self::checkRankingKeys($options['ranking']);
            $rk = array_replace($rk, $options['ranking']);
        }

        $fields = !empty($options['fields']) ? $options['fields'] : $this->getDefaultFields();
        $fields = array_filter($fields, fn($w) => $w > 0);
        if (empty($fields)) return $this->emptyResult($page, $perPage, $algo);

        // Reset the per-search wildcard-candidate memo (see prefixCandidates()).
        $this->prefixCandidateCache = [];

        // Diagnostics channel (opt-in). Everything below already exists
        // as pipeline state — collection is append-only inside guards, so the
        // flag-off path does no extra work. Born of three demands that hit the
        // same wall: the search log couldn't record typo-swap events, two
        // auto-routing bugs stayed invisible for weeks because
        // routing evidence died inside this method, and the UI had no honest
        // way to show users what their query was expanded to.
        $collectDiag = !empty($options['diagnostics']);
        $diagExp = []; $diagSwaps = []; $diagDropped = []; $diagWild = []; $diagAuto = null;

        // --- Parse query: terms + boosts + wildcard prefixes ---
        $parsed   = $this->parseQuery($query);
        $required = $parsed['required'];
        $excluded = $parsed['excluded'];
        $optional = $parsed['optional'];
        $boosts   = $parsed['boosts'];    // [term => float]
        $reqWild  = $parsed['req_wild'];  // [prefix => boost]
        $excWild  = $parsed['exc_wild'];
        $optWild  = $parsed['opt_wild'];
        // Excluded PHRASES are per-field AND groups (see passesExcluded)
        $excPhrases = $parsed['exc_phrases'];
        // Required phrases as ordered groups (words are ALSO in
        // $required, unchanged — see parseQuery).
        $reqPhrases = $parsed['req_phrases'];

        // Positional phrase verification engages when the query has
        // quoted phrases, the caller didn't force 'unordered', and EVERY
        // active field's postings carry positions. All-or-nothing on purpose:
        // with a mixed index, a phrase living in a position-less field could
        // never verify, silently hiding docs — our builders emit uniform
        // schemas, so mixed means something is wrong; degrade to unordered
        // word-level semantics.
        $phraseMode        = $options['phrase_mode'] ?? 'auto';
        $positionalPhrases = false;
        if ((!empty($reqPhrases) || !empty($excPhrases)) && $phraseMode !== 'unordered') {
            $positionalPhrases = true;
            foreach (array_keys($fields) as $pf) {
                if (!$this->positionsAvailable($pf)) { $positionalPhrases = false; break; }
            }
        }

        // Tracks whether ANY expansion added scoring terms (stem/syn/
        // morph/fuzzy variants, wildcard expansions). When none did, every
        // term is its own concept and concept coverage is DEFINITIONALLY
        // identical to per-term accounting — so the concept-structure build
        // is skipped (measured +23ms on plain multi-word queries, the most
        // common class). Must be final only AFTER all expansion paths ran;
        // fuzzy resolves last.
        $expansionOccurred = !empty($reqWild) || !empty($optWild);

        // --- Auto algorithm selection ---
        // Picks the best algorithm based on query characteristics. Question-shape
        // is detected HERE, before stopword removal — measured: bm25f wins
        // question queries whether or not stopwords are later stripped
        // (MRR .833/.763 vs cover .607/.643), but classifying after removal
        // would lose the question shape when the filter eats the interrogative.
        //
        // The mapping was rebuilt from a 75-query labeled quality study (MRR /
        // hit@1 per query class). The first, intuition-based mapping ranked
        // auto 6th of 9 algorithms overall; measured per class:
        //   wildcards:  freq .667 vs bm25 .367 — the expansion cap drops the
        //               commonest completion ("computer"), IDF-weighted algos
        //               chase rare variants (astro* -> "Astrocytoma"); raw
        //               frequency accumulates the canonical article's variants.
        //   real typos: freq .74-.83 vs rrf .52-.63 — rank fusion amplifies
        //               noise-variant docs ("grammer" -> Tonbridge Grammar
        //               School). BUT conf<100 alone is NOT typo evidence (the
        //               UI default is 85, so clean queries land here too, and
        //               freq famously favors long docs on clean queries) —
        //               the freq route is taken only on actual typo EVIDENCE,
        //               decided after fuzzy expansion below.
        //   questions:  bm25f .833, clearly ahead — kept from the old mapping.
        //   everything else (exact, any term count): cover 1.0 on common /
        //               abstract / entity / operator / ambiguous classes; the
        //               title bonus + token-count tiebreaker do the ranking
        //               work and flat coverage lets them. dfr (the earlier
        //               single-word pick) fails on people: its short-doc preference
        //               surfaces relatives (einstein -> "Arik Einstein",
        //               mozart -> "Leopold Mozart"; MRR .474 vs cover .767).
        //
        // Earlier (intuition-based) mapping:
        //   wildcards -> 'bm25'; conf<100 -> 'rrf'; termCount<=1 -> 'dfr';
        //   else -> 'bm25f'
        if ($algo === 'auto') {
            $hasWildcards = !empty($reqWild) || !empty($optWild);

            // Interrogatives only — auxiliaries alone don't make a question.
            static $QUESTION_WORDS = ['how','what','which','who','whom','whose','when','where','why'];
            $hasQuestion = (bool)array_intersect($QUESTION_WORDS, array_merge($required, $optional));

            if ($hasWildcards) {
                $algo = 'freq';
            } elseif ($confidence < 100) {
                // Defer: becomes 'freq' if the fuzzy machinery finds typo
                // evidence, otherwise falls back per shape. Resolved right
                // after the fuzzy noise filter (search for AUTO_PENDING).
                $algo = self::AUTO_PENDING;
                $autoFallback = $hasQuestion ? 'bm25f' : 'cover';
            } elseif ($hasQuestion) {
                $algo = 'bm25f';
            } else {
                $algo = 'cover';
            }
        }

        // --- Apply stopwords to optional terms only ---
        if (!empty($stopwords)) {
            $optional = array_values(array_diff($optional, $stopwords));
        }

        // Snapshot the words the user actually TYPED, before any
        // expansion. $presynOptional (captured after the stemming block) is
        // pre-SYNONYM but not pre-stemming — it contains stem variants, and
        // stemming generates deliberately-blind non-words ("photosynthesis"
        // -> "photosynthesi", df=0). The AUTO_PENDING typo-evidence check
        // used to iterate $presynOptional and read such a variant's df=0 as
        // "the user typed a corpus-unknown word", routing stemmed question
        // queries to freq (found by comparing against live-Wikipedia
        // CirrusSearch: "how does photosynthesis work" + stemming returned
        // Science/Chemistry/"Deaths in 2010"). Typo evidence must come from
        // typed words only.
        $typedOptional = $optional;

        // --- Apply stemming: add root variants ---
        // The first version replaced each list wholesale with rootWords() of
        // itself (see the Earlier: block below). That had two flaws:
        //   1. $required was FLATTENED to originals+variants, and each entry
        //      became an independent AND requirement downstream — so "+wolves"
        //      with stemming demanded wolves AND wolf AND wolfe in the same
        //      doc (measured: 453 docs -> 5). Variants must be OR-alternatives
        //      within their original's requirement group.
        //   2. Variants rode at full boost 1.0 ("Root variants get default
        //      boost") — the only expansion class with no discount. They now
        //      get stem_boost (default 0.8) x the original's boost, so a rule
        //      collision (hated -> "hat") can add recall but never outrank
        //      the word the user actually typed.
        // Side benefit: $required now stays originals-only, so the title
        // bonus / coverage-prune / proximity term lists no longer treat stem
        // variants of required terms as words the user typed.
        // Earlier:
        // $required = self::rootWords($required);
        // $excluded = self::rootWords($excluded);
        // $optional = self::rootWords($optional);
        $reqStemGroups  = [];
        $optStemGroups  = [];
        $optVariantSet  = [];
        if ($doStem) {
            // Save original terms BEFORE stemming for morph expansion (done later).
            $origForMorph = array_merge($required, $optional);

            // Required: per-original OR-groups (consumed by the reqExpansions
            // build below); $required itself keeps only the user's terms.
            foreach ($required as $t) {
                $reqStemGroups[$t] = self::rootWords([$t]);   // [orig, variants...]
                foreach ($reqStemGroups[$t] as $v) {
                    // Don't discount a variant that is itself a typed term.
                    if ($v === $t || in_array($v, $required, true) || in_array($v, $optional, true)) continue;
                    $boosts[$v] = $rk['stem_boost'] * ($boosts[$t] ?? 1.0);
                }
                // Risky rules, dictionary-verified — kept only when the
                // verifier confirms a derivational link (quickly->quick yes,
                // summer->sum no). Verified roots join the OR-group at
                // derivation_boost (they're derivations, not inflections).
                if ($stemVerifier !== null) {
                    foreach (self::riskyRootCandidates($t) as $rc) {
                        if (in_array($rc, $reqStemGroups[$t], true) || !$stemVerifier($t, $rc)) continue;
                        $reqStemGroups[$t][] = $rc;
                        if (!in_array($rc, $required, true) && !in_array($rc, $optional, true)) {
                            $boosts[$rc] = $rk['derivation_boost'] * ($boosts[$t] ?? 1.0);
                        }
                    }
                }
                if (count($reqStemGroups[$t]) > 1) $expansionOccurred = true;
            }

            // Excluded: exclusion stays broad — a doc containing ANY form of
            // an excluded word is excluded (boosts are irrelevant here).
            $excluded = self::rootWords($excluded);

            // Optional: variants appended with stem_boost, originals untouched.
            // $optStemGroups / $optVariantSet feed the concept-based title
            // bonus below: a variant is a FORM of its original's concept, not
            // a concept of its own.
            $optAdd = [];
            foreach ($optional as $t) {
                $optStemGroups[$t] = self::rootWords([$t]);
                foreach ($optStemGroups[$t] as $v) {
                    if ($v === $t || in_array($v, $optional, true)
                        || in_array($v, $required, true) || in_array($v, $optAdd, true)) continue;
                    $optAdd[] = $v;
                    $optVariantSet[$v] = true;
                    $boosts[$v] = $rk['stem_boost'] * ($boosts[$t] ?? 1.0);
                }
                // Dictionary-verified risky roots — forms of $t's
                // concept (they join optStemGroups, so the title bonus and
                // coverage slots treat them correctly), at derivation_boost.
                if ($stemVerifier !== null) {
                    foreach (self::riskyRootCandidates($t) as $rc) {
                        if (in_array($rc, $optStemGroups[$t], true) || !$stemVerifier($t, $rc)) continue;
                        $optStemGroups[$t][] = $rc;
                        if (in_array($rc, $optional, true) || in_array($rc, $required, true)
                            || in_array($rc, $optAdd, true)) continue;
                        $optAdd[] = $rc;
                        $optVariantSet[$rc] = true;
                        $boosts[$rc] = $rk['derivation_boost'] * ($boosts[$t] ?? 1.0);
                    }
                }
            }
            if (!empty($optAdd)) $expansionOccurred = true;
            $optional = array_merge($optional, $optAdd);
        }

        // Diagnostics: stem-class expansions (incl. OEWN-verified risky roots,
        // which live inside the same groups at derivation_boost).
        if ($collectDiag && $doStem) {
            foreach ([$reqStemGroups, $optStemGroups] as $diagGroups) {
                foreach ($diagGroups as $diagOrig => $diagForms) {
                    foreach ($diagForms as $diagF) {
                        if ($diagF !== $diagOrig) {
                            $diagExp[] = ['term' => $diagF, 'from' => $diagOrig,
                                          'class' => 'stem', 'boost' => round($boosts[$diagF] ?? 1.0, 3)];
                        }
                    }
                }
            }
        }

        // --- Concept provenance ---
        // Maps every term (typed or expansion-added) to the CONCEPT — the
        // typed word — it derives from, plus each concept's weight (the user's
        // boost). Consumers: the concept-based title bonus and, when the
        // 'concept_coverage' ranking flag is on, the coverage denominators.
        // Recording is cheap and unconditional; the scoring changes are gated.
        $conceptOf     = [];   // term => concept key
        $conceptWeight = [];   // concept key => weight (user boost)
        foreach ($required as $t) {
            $conceptWeight['r:' . $t] = $boosts[$t] ?? 1.0;
            foreach (($reqStemGroups[$t] ?? [$t]) as $f) $conceptOf[$f] ??= 'r:' . $t;
        }
        foreach (array_keys($optStemGroups) ?: $optional as $t) {
            $conceptWeight['o:' . $t] = $boosts[$t] ?? 1.0;
            foreach (($optStemGroups[$t] ?? [$t]) as $f) $conceptOf[$f] ??= 'o:' . $t;
        }

        // Track original optional terms before synonym expansion so we can
        // skip fuzzy matching on synonym-added terms (prevents chains like
        // monster → fiend ~90%→ friend at confidence 85).
        $presynOptional = $optional;

        // --- Apply synonyms to optional terms ---
        if (!empty($synonyms)) {
            $origOptional = $optional;
            foreach ($origOptional as $term) {
                foreach ($synonyms as $group) {
                    if (in_array($term, $group)) {
                        foreach ($group as $syn) {
                            if ($syn !== $term && !in_array($syn, $optional)) {
                                $optional[] = $syn;
                                $boosts[$syn] = $boosts[$syn] ?? ($boosts[$term] ?? 1.0);
                                // a synonym is a FORM of the term that triggered it
                                $conceptOf[$syn] ??= $conceptOf[$term] ?? 'o:' . $term;
                                $expansionOccurred = true;
                                if ($collectDiag) $diagExp[] = ['term' => $syn, 'from' => $term,
                                    'class' => 'synonym', 'boost' => round($boosts[$syn], 3)];
                            }
                        }
                        break;
                    }
                }
            }
            $optional = array_values(array_unique($optional));
        }

        // --- Derivational expansion ---
        // OEWN-linked related words become FORMS of the triggering term's
        // concept: "decision" also searches decide, decisive... — reaching
        // what suffix rules cannot (cross-POS, irregular derivations). Groups
        // come from the app (OewnSynonyms::derivationsFor) so the engine
        // stays lexicon-agnostic, same boundary as synonyms. Added AFTER the
        // presynOptional capture, so derivation terms are exact-matched only —
        // never fuzzy-expanded (same chain-prevention as synonyms/morph).
        if (!empty($derivGroups)) {
            foreach ($derivGroups as $group) {
                $term = $group[0] ?? null;
                if ($term === null || !in_array($term, $optional, true)) continue;
                foreach (array_slice($group, 1) as $d) {
                    if (in_array($d, $optional, true) || in_array($d, $required, true)) continue;
                    $optional[] = $d;
                    $boosts[$d] = $rk['derivation_boost'] * ($boosts[$term] ?? 1.0);
                    $conceptOf[$d] ??= $conceptOf[$term] ?? 'o:' . $term;
                    $expansionOccurred = true;
                    if ($collectDiag) $diagExp[] = ['term' => $d, 'from' => $term,
                        'class' => 'derivation', 'boost' => round($boosts[$d], 3)];
                }
            }
        }

        // --- Morphological expansion (bidirectional stemming) ---
        // Added AFTER presynOptional save so morph variants are NOT fuzzy-expanded.
        // Tried: adding before presynOptional — morph terms got fuzzy-expanded,
        // causing 16x regression (0.5s → 8.4s) because 7 morph variants × 10 fuzzy
        // matches each = 70 extra terms in the candidate set.
        // By adding after, morph variants join synOnlyOptional and are matched exact.
        if ($doStem && !empty($origForMorph)) {
            // Expanded per ORIGIN so each variant's concept is known (identical
            // output to one flat morphExpand($origForMorph) call — morphExpand
            // verifies each word's candidates independently; the in_array
            // guards dedupe across origins).
            foreach ($origForMorph as $origin) {
                foreach ($this->morphExpand([$origin]) as $mt) {
                    if (!in_array($mt, $optional) && !in_array($mt, $required)) {
                        $optional[] = $mt;
                        // flat 'morph_boost' (0.7 by default), not scaled by the origin's boost
                        $boosts[$mt] = $rk['morph_boost'];
                        $conceptOf[$mt] ??= $conceptOf[$origin] ?? 'o:' . $origin;
                        $expansionOccurred = true;
                        if ($collectDiag) $diagExp[] = ['term' => $mt, 'from' => $origin,
                            'class' => 'morph', 'boost' => round($boosts[$mt], 3)];
                    }
                }
            }
        }

        // Terms added by ANY expansion class — matched exact only, never fuzzed.
        // Diff against the TYPED snapshot, so stem variants land here alongside
        // synonym/derivation/morph terms. When this diffed against
        // $presynOptional instead, stem variants rode in the fuzzed list (see
        // the expandFlat call below). Blind stem non-words ("photosynthesi")
        // now simply match nothing, which is the safety property rootWords()
        // was designed around.
        $synOnlyOptional = array_values(array_diff($optional, $typedOptional));

        if (empty($required) && empty($optional) && empty($reqWild) && empty($optWild)) {
            return $this->emptyResult($page, $perPage, $algo);
        }


        // --- Build required expansions [key => [expanded_terms]] ---
        // Required terms are always exact — fuzzy only applies to optional terms.
        // With stemming on, each required term's group is [orig, stem
        // variants...] — passesRequired() ORs within a group, so "+wolves"
        // means "wolves OR wolf", not "wolves AND wolf" (the earlier flattened
        // list made every variant an independent AND requirement).
        $reqExpansions = !empty($reqStemGroups)
            ? $reqStemGroups
            : $this->expandRequired($required);

        // Wildcard required: expand per field, union across fields
        foreach ($reqWild as $prefix => $boost) {
            $conceptWeight['rw:' . $prefix] = $boost;   // one concept per prefix
            $expanded = [];
            foreach (array_keys($fields) as $field) {
                foreach ($this->expandPrefix($field, $prefix, $maxWild) as $t) {
                    $expanded[] = $t;
                    $boosts[$t] = $boosts[$t] ?? $boost;
                    $conceptOf[$t] ??= 'rw:' . $prefix;
                }
            }
            $key = $prefix . '*';
            $reqExpansions[$key] = array_values(array_unique($expanded));
            if ($collectDiag) foreach ($reqExpansions[$key] as $diagT) $diagWild[$key][$diagT] = true;
        }

        // Flat list of all required expanded terms for postings fetch
        $allReqExpanded = array_values(array_unique(
            empty($reqExpansions) ? [] : array_merge(...array_values($reqExpansions))
        ));

        // --- Build per-field excluded and optional term sets ---
        // Excluded terms are exact-only BY DESIGN (fuzzy-excluding would hide
        // documents the user never asked to hide).
        // Direct assignment. An earlier array_keys($this->expandFlat($excluded,
        // 100)) was an identity function in disguise — expandFlat() with
        // confidence pinned at 100 short-circuits to array_fill_keys($terms,
        // 1.0). It read as if excluded terms were fuzzy-expanded; they never
        // were.
        $excExpanded = $excluded;

        // Fuzzy expansion runs on the words the user TYPED, and nothing else.
        //
        // Two lessons are baked into that line. First, expandFlat() returns
        // [term => boost] with distance-based discounting (fuzzy_decay ^
        // distance), and the fuzzy boost multiplies any existing boost (a
        // user's ^2). The first version merged variants in flat at boost 1.0,
        // the same as the originals:
        //   Earlier: $optExpanded = array_merge(
        //                $this->expandFlat($presynOptional, $confidence),
        //                $synOnlyOptional);
        // Learned: this is the right layer to apply discounting — scoreDoc()
        // already reads $boosts[$term] ?? 1.0, so nothing downstream changed.
        // Second, the input used to be $presynOptional, which is pre-SYNONYM
        // but not pre-stemming: stem variants were the one machine-generated
        // class that got fuzzy-expanded — inherited from the wholesale
        // rootWords() replacement, never a decision. Consequences: rule-
        // collision stems fuzzed into unrelated common words (hated -> hat ->
        // had/ham at 0.5), and fuzzy-of-stem candidates rode at plain fuzzy
        // boost 0.5 instead of a compounded stem*fuzzy discount (0.4) because
        // $boosts had no entry for the new candidate — a trust-ladder
        // violation. Stem variants now sit in the exact-only set with morph,
        // synonym and derivation terms, same chain-prevention rationale.
        //
        // $fuzzyByOrigin records which typed word produced each variant, so
        // the noise filter below compares every term against ITS OWN variants.
        $fuzzyByOrigin = [];
        $fuzzyResult = $this->expandFlat($typedOptional, $confidence, $maxFuzzy, $fuzzyByOrigin, $rk['fuzzy_decay']);
        // fuzzy variants are forms of the term that produced them
        foreach ($fuzzyByOrigin as $origin => $variants) {
            foreach (array_keys($variants) as $v) {
                $conceptOf[$v] ??= $conceptOf[$origin] ?? 'o:' . $origin;
                if ($v !== $origin) $expansionOccurred = true;
            }
        }

        // --- Fuzzy noise filter: detect when query term is a rare typo ---
        // Problem: user types "einstien" (a typo). The term "einstien" actually exists
        // in the index (it's a misspelling in some Wikipedia articles) with doc_freq=3.
        // "einstein" is the fuzzy expansion with doc_freq=300+. Without this filter,
        // "einstien" gets boost=1.0 (exact match) and "einstein" gets 0.5 (distance 1).
        // BM25F then ranks short articles containing the rare typo above "Albert Einstein".
        //
        // Fix: for each original query term, check its doc_freq vs the best fuzzy variant.
        // If a fuzzy variant has 10x+ more doc_freq, the original is likely a corpus typo,
        // not a real word. Swap boosts: give the common variant boost=1.0 and demote the
        // rare original to 0.3 (lower than standard fuzzy 0.5 since it's noise).
        //
        // Only applies when fuzzy is active (confidence < 100).
        // Earlier this compared doc_freq from the body field only ("most
        // comprehensive term coverage"); it now sums doc_freq across all
        // active fields — corpus-agnostic, no field name assumed.
        // Track fuzzy-promoted variants for title bonus matching later.
        // When we detect a query term is a rare typo and promote its common variant,
        // we need the title bonus to also check for that variant in titles.
        $fuzzyPromotedTerms  = [];
        $fuzzyPromotedOrigin = [];  // promoted variant => the typed word it corrects
                                    // (consumed by the title_promoted_as_form knob)
        // Per-field termstats fetched here are CACHED for the scoring
        // prefetch below (see "Drop noisy optional terms"). $prefetchRequested
        // records which terms were queried so the later fetch can skip them
        // even when a term simply doesn't exist in some field.
        $prefetchedTermStats = [];
        $prefetchRequested   = [];
        $dfMap               = [];   // also read by the AUTO_PENDING resolution below

        if ($confidence < 100 && !empty($fuzzyResult)) {
            // Typed words only — matches the expandFlat base above, so the
            // noise filter never asks "is this stem variant a corpus typo?"
            // A df=0 typed word is absent from unique_terms, so it never
            // appears as a KEY of $fuzzyResult — only as an ORIGIN of variants
            // in $fuzzyByOrigin. Admit those too, or the swap_zero_df_promote
            // branch below can never see exactly the words it exists for.
            $origTerms = array_filter($typedOptional,
                fn($t) => isset($fuzzyResult[$t]) || !empty($fuzzyByOrigin[$t]));
            if (!empty($origTerms)) {
                // Batch-fetch doc_freq for all fuzzy result terms via
                // fetchTermStats(), keeping the per-field results. Earlier code
                // ran its own raw termstats queries and threw the per-field data
                // away after summing into $dfMap — so on fuzzy queries every
                // fuzzy term's stats were fetched TWICE (once here, once in the
                // scoring prefetch); the "fetch once, reuse everywhere" claim
                // only held for non-fuzzy queries. fetchTermStats also
                // populates the pre-computed IDF cache. (Before the per-field
                // tables there was a single unified term_stats table:
                // SELECT term, SUM(doc_freq) FROM term_stats ... GROUP BY term.)
                // Earlier:
                // foreach (array_keys($fields) as $_f) {
                //     $tbl = "termstats_$_f";
                //     foreach (array_chunk($allFuzzyTerms, self::BATCH_SIZE) as $batch) {
                //         $ph = implode(',', array_fill(0, count($batch), '?'));
                //         $stmt = $this->db->prepare("SELECT term, doc_freq FROM $tbl WHERE term IN ($ph)");
                //         $stmt->execute($batch);
                //         while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                //             $dfMap[$row['term']] = ($dfMap[$row['term']] ?? 0) + (int)$row['doc_freq'];
                //         }
                //     }
                // }
                $allFuzzyTerms     = array_keys($fuzzyResult);
                $prefetchRequested = $allFuzzyTerms;
                $dfMap = [];
                foreach (array_keys($fields) as $_f) {
                    $prefetchedTermStats[$_f] = $this->fetchTermStats($_f, $allFuzzyTerms);
                    foreach ($prefetchedTermStats[$_f] as $t => $df) {
                        $dfMap[$t] = ($dfMap[$t] ?? 0) + $df;
                    }
                }

                foreach ($origTerms as $orig) {
                    $origDf = $dfMap[$orig] ?? 0;
                    // Find the best fuzzy variant (highest doc_freq, not the original).
                    //
                    // Bug fix: scan only THIS term's own variants. Earlier code
                    // scanned the whole flat $fuzzyResult map — a holdover from
                    // when expandFlat() returned a flat array with no record of which
                    // query term produced which variant. On a multi-word fuzzy query
                    // like "solar systm", the "best variant" for systm could be the
                    // unrelated exact term "solar": solar's doc_freq easily clears
                    // the 10x threshold, so systm was demoted to 0.3 based on another
                    // word's frequency, and "solar" was re-appended to the title-bonus
                    // term list, diluting every doc's match ratio.
                    // Earlier:
                    // foreach ($fuzzyResult as $term => $boost) {
                    //     if ($term === $orig) continue;
                    //     $tDf = $dfMap[$term] ?? 0;
                    //     if ($tDf > $bestDf) { $bestDf = $tDf; $bestVariant = $term; }
                    // }
                    $bestVariant = null;
                    $bestDf = 0;
                    foreach (array_keys($fuzzyByOrigin[$orig] ?? []) as $term) {
                        if ($term === $orig) continue;
                        $tDf = $dfMap[$term] ?? 0;
                        if ($tDf > $bestDf) {
                            $bestDf = $tDf;
                            $bestVariant = $term;
                        }
                    }
                    // If the best variant has 10x+ more doc_freq, the original is likely
                    // a rare corpus typo. Swap boosts to prefer the common variant.
                    // Threshold 10x chosen empirically: "einstien"(3) vs "einstein"(300+)
                    // is 100x, clearly a typo. "smart"(200) vs "shart"(5) is 40x the other
                    // way — "smart" is the original so this check won't fire (smart has
                    // higher df). Only fires when the ORIGINAL term is the rare one.
                    // The 10x threshold is the 'typo_swap_ratio' knob (default 10.0).
                    // swap_orig_df_max: a df ceiling on the original — real
                    // corpus typos sit at df 0-5; an established rare word
                    // ("rukh" df=88) should never be "corrected". 0 = no ceiling.
                    $underDfCeiling = $rk['swap_orig_df_max'] <= 0
                        || $origDf <= $rk['swap_orig_df_max'];

                    if ($bestVariant !== null && $bestDf > 0 && $origDf > 0
                        && $underDfCeiling
                        && $bestDf >= $rk['typo_swap_ratio'] * $origDf
                    ) {
                        // swap_context_check: before trusting the df ratio, ask
                        // whether the OTHER query words vouch for the original —
                        // the fraction of its docs that also contain another
                        // query word. "rukh" passes (its docs are Shah Rukh Khan
                        // pages, full of shah/khan); "einstien" fails (its typo
                        // docs rarely contain a second query word). Runs only
                        // when the swap would otherwise fire, so the extra
                        // postings probe costs nothing on clean queries.
                        if ($rk['swap_context_check'] > 0) {
                            $contextTerms = array_diff(
                                array_unique(array_merge($required, $typedOptional)), [$orig]);
                            if (!empty($contextTerms)) {
                                $vouch = $this->contextVouchRatio(
                                    array_keys($fields), $orig, $contextTerms);
                                if ($vouch >= $rk['swap_context_check']) {
                                    if ($collectDiag) $diagSwaps[] = ['typed' => $orig,
                                        'promoted' => null, 'typed_df' => $origDf,
                                        'promoted_df' => $bestDf,
                                        'blocked_by_context' => round($vouch, 3)];
                                    continue;
                                }
                            }
                        }
                        // Demote the rare original, promote the common variant
                        $fuzzyResult[$orig] = 0.3;       // lower than standard fuzzy 0.5
                        $fuzzyResult[$bestVariant] = 1.0; // treat as the "real" term
                        if ($collectDiag) $diagSwaps[] = ['typed' => $orig, 'promoted' => $bestVariant,
                            'typed_df' => $origDf, 'promoted_df' => $bestDf];
                        // Track promoted variants so title bonus can check them too.
                        // Unless the variant is ALSO one of the user's own query
                        // terms — it would then appear twice in the title-bonus term
                        // list and inflate the denominator (weaker bonus for
                        // everyone), which an earlier unconditional append did.
                        if (!in_array($bestVariant, $required, true)
                            && !in_array($bestVariant, $presynOptional, true)
                        ) {
                            $fuzzyPromotedTerms[] = $bestVariant;
                            $fuzzyPromotedOrigin[$bestVariant] = $orig;
                        }
                    } elseif ($bestVariant !== null && $origDf === 0
                        && $rk['swap_zero_df_promote'] > 0
                        && $bestDf >= $rk['swap_zero_df_promote']
                    ) {
                        // swap_zero_df_promote: the typed word matches NOTHING —
                        // stronger typo evidence than the df-ratio case above,
                        // which can't fire at df=0 (ratio over zero). Promote the
                        // best variant to full boost; nothing to demote, since a
                        // df=0 original scores no documents anyway. This is what
                        // lets "wikipeedia" treat "wikipedia" as the real query
                        // word (title bonus + noise-filter exemption downstream).
                        $fuzzyResult[$bestVariant] = 1.0;
                        if ($collectDiag) $diagSwaps[] = ['typed' => $orig,
                            'promoted' => $bestVariant, 'typed_df' => 0,
                            'promoted_df' => $bestDf];
                        if (!in_array($bestVariant, $required, true)
                            && !in_array($bestVariant, $presynOptional, true)
                        ) {
                            $fuzzyPromotedTerms[] = $bestVariant;
                            $fuzzyPromotedOrigin[$bestVariant] = $orig;
                        }
                    }
                }
            }
        }

        // --- AUTO_PENDING resolution ---
        // auto + conf<100 was deferred until now: route to freq ONLY on typo
        // EVIDENCE — (a) the noise filter promoted a common variant over a rare
        // original ("einstien" df=3 vs "einstein" df=300+), or (b) an original
        // query term doesn't exist in the corpus at all ("dinasaur", "frnch").
        // Clean queries merely searched at the UI's default confidence 85 fall
        // through to the exact-query mapping (question -> bm25f, else cover) —
        // measured: freq on clean multi-word queries favors long docs ("bob
        // einstein" -> the "Bob" article), while on real typos freq beats the
        // earlier rrf route .74-.83 vs .52-.63 MRR.
        if ($algo === self::AUTO_PENDING) {
            // auto_typo_fraction: route to freq only when suspect words cover
            // at least this fraction of the typed words. A suspect is a typed
            // word whose variant got promoted, or one with df=0. (Typed
            // words only — stem variants in $presynOptional include blind
            // non-words whose df=0 is NOT typo evidence; see the
            // $typedOptional snapshot above.) At the default 0.0 this is the
            // historical any-evidence rule: one suspect routes the query.
            $suspects = [];
            foreach ($fuzzyPromotedOrigin as $promotedFrom) {
                $suspects[$promotedFrom] = true;
            }
            foreach ($typedOptional as $ot) {
                if (($dfMap[$ot] ?? 0) === 0) $suspects[$ot] = true;
            }
            $suspectCount = count($suspects);
            $typedCount   = max(1, count(array_unique($typedOptional)));
            $typoEvidence = $suspectCount > 0
                && ($suspectCount / $typedCount) >= $rk['auto_typo_fraction'];
            $algo = $typoEvidence ? 'freq' : $autoFallback;
            if ($collectDiag) $diagAuto = ['typo_evidence' => $typoEvidence,
                'suspects' => array_keys($suspects), 'typed_words' => $typedCount];
        }

        foreach ($fuzzyResult as $term => $fuzzyBoost) {
            $boosts[$term] = ($boosts[$term] ?? 1.0) * $fuzzyBoost;
        }
        if ($collectDiag) {
            foreach ($fuzzyByOrigin as $diagOrig => $diagVars) {
                foreach (array_keys($diagVars) as $diagV) {
                    if ($diagV !== $diagOrig) {
                        $diagExp[] = ['term' => $diagV, 'from' => $diagOrig,
                                      'class' => 'fuzzy', 'boost' => round($boosts[$diagV] ?? 1.0, 3)];
                    }
                }
            }
        }
        $optExpanded = array_merge(array_keys($fuzzyResult), $synOnlyOptional);

        // Bug fix: excluded WILDCARDS do not expand to terms at all.
        //
        // The earlier approach expanded the prefix via expandPrefix() — whose cap keeps
        // the RAREST expansions. Right policy for optional/required terms
        // (rare = discriminative), exactly backwards for exclusion: -americ*'s
        // most COMMON completions (american, america — the ones users mean)
        // fell off the list, so docs containing them leaked through. Measured:
        // "history -mo*" leaked 32 of the top 50; "planet -th*" leaked 49/50.
        // Uncapping through the old machinery was correct but fetched every
        // posting row of every expanded term into PHP (1.5M rows / 3.5s for th*).
        //
        // New approach: exclusion needs only a DOC-ID SET, not terms/postings.
        // One SQL range scan per field per prefix (DISTINCT doc_id) gives the
        // complete set — no cap, no term expansion, no posting materialization.
        // passesExcluded() checks the set first. max_wildcard_expansions no
        // longer applies to exclusions (completeness is the point of "-").
        // Earlier:
        // foreach ($excWild as $prefix => $boost) {
        //     $excTerms = array_merge($excTerms, $this->expandPrefix($field, $prefix, $maxWild));
        // }
        $excludedDocIds = $this->excludedDocIdsForPrefixes(array_keys($excWild), array_keys($fields));

        // Excluded-phrase words must be FETCHED (passesExcluded checks
        // their postings per field) without joining the single-term exclusion
        // list. Kept exact — no stemming/fuzzing of phrase words: a quoted
        // phrase is precise intent, same policy as required phrases.
        // In POSITIONAL mode they are deliberately NOT added to the
        // broad postings fetch — verification reads positions via a targeted
        // per-candidate fetch instead. This matters: a slop-filter phrase like
        // -"in today's fast-paced world" is made of corpus-wide common words,
        // and fetching their full posting lists here would pull millions of
        // rows to answer a question the candidate-bounded fetch answers cheaply.
        $excPhraseWords = (empty($excPhrases) || $positionalPhrases)
            ? []
            : array_values(array_unique(array_merge(...$excPhrases)));

        $excByField = [];
        $optByField = [];

        foreach (array_keys($fields) as $field) {
            $excTerms = $excExpanded;   // exact excluded terms only (see above)
            $excByField[$field] = array_values(array_unique($excTerms));

            $optTerms = $optExpanded;
            foreach ($optWild as $prefix => $boost) {
                $conceptWeight['ow:' . $prefix] = $boost;   // one concept per prefix
                $expanded = $this->expandPrefix($field, $prefix, $maxWild);
                foreach ($expanded as $t) {
                    $boosts[$t] = $boosts[$t] ?? $boost;
                    $conceptOf[$t] ??= 'ow:' . $prefix;
                }
                if ($collectDiag) foreach ($expanded as $diagT) $diagWild[$prefix . '*'][$diagT] = true;
                $optTerms = array_merge($optTerms, $expanded);
            }
            $optByField[$field] = array_values(array_unique($optTerms));
        }

        // --- Drop noisy optional terms ---
        // Two filters to prevent result set explosion:
        //  1. Corpus stopwords: drop terms in >25% of docs (near-zero IDF, causes OOM)
        //  2. Fuzzy noise: drop fuzzy-expanded terms that are 3x+ more frequent than
        //     the original query term. e.g., "start" (6669 docs) as a fuzzy match for
        //     "smart" (1319 docs) inflates the candidate set with irrelevant results.
        //
        // fieldStats and termStats are fetched ONCE here and cached for reuse.
        // Earlier, fetchFieldStats was called TWICE (here and again in the
        //   "--- Pre-fetch stats for IDF/BM25 ---" block below) and fetchTermStats
        //   TWICE per field (here for noise filtering, again there for IDF/BM25
        //   scoring). Cost: 2 redundant SQL round-trips for fieldStats + 3 for
        //   termStats (one per field) — on fuzzy queries ~50-100ms of pure waste.
        //   (A version of this comment cited line numbers, which drifted ~200
        //   lines from the code they described — section names age better.)
        //
        // Now: fetch once, store in $fieldStats and $termStatsByF, reuse everywhere
        //   downstream. The scoring prefetch block below skips its own fetch.
        $fieldStats = $this->fetchFieldStats(array_keys($fields));
        // Error contract: a field name the index doesn't have used to
        // surface later as PDO's "no such table: postings_<name>" — obscure
        // and far from the mistake. Throw here with the actual roster.
        $unknownFields = array_diff(array_keys($fields), array_keys($fieldStats));
        if (!empty($unknownFields)) {
            throw new \InvalidArgumentException(
                'Unknown field(s): ' . implode(', ', $unknownFields)
                . ' (index has: ' . implode(', ', array_keys($fieldStats)) . ')'
            );
        }
        $termStatsByF = [];
        $originalTerms = array_merge($required, $presynOptional); // user's actual query terms
        foreach (array_keys($fields) as $field) {
            $totalDocs = $fieldStats[$field]['total_docs'] ?? 0;

            // Fetch termStats for ALL terms in this field (optional + required + excluded).
            // Results stored in $termStatsByF[$field] and reused for IDF/BM25 scoring.
            //
            // On fuzzy queries the noise filter above already fetched stats
            // for every fuzzy term (and cached them in $prefetchedTermStats), so
            // only the terms it did NOT request are fetched here — wildcard
            // expansions, required terms, synonym/morph additions. We diff against
            // the REQUESTED list, not the found keys, so terms absent from this
            // field aren't pointlessly re-queried.
            $allFieldTerms = array_unique(array_merge($allReqExpanded, $optByField[$field]));
            $cached  = $prefetchedTermStats[$field] ?? [];
            $missing = array_diff($allFieldTerms, $prefetchRequested);
            $termStatsByF[$field] = $cached + (!empty($missing) ? $this->fetchTermStats($field, $missing) : []);
            $docFreqs = $termStatsByF[$field];

            if ($totalDocs < 100) continue;
            // The cutoff is config (highfreq_cutoff, 0.25 by default); >= 1.0
            // means never drop common terms.
            $corpusThreshold = $hfCutoff >= 1.0 ? PHP_INT_MAX : (int)($totalDocs * $hfCutoff);
            $candidates = $optByField[$field];
            if (empty($candidates)) continue;

            // Find max doc_freq among original query terms (for fuzzy noise filter)
            $origMaxFreq = 0;
            foreach ($originalTerms as $ot) {
                $origMaxFreq = max($origMaxFreq, $docFreqs[$ot] ?? 0);
            }
            $fuzzyThreshold = max(1000, $origMaxFreq * 3); // 3x original or 1000, whichever is higher

            $optByField[$field] = array_values(array_filter($candidates, function($t) use ($docFreqs, $corpusThreshold, $fuzzyThreshold, $originalTerms, $fuzzyPromotedTerms, $rk) {
                $df = $docFreqs[$t] ?? 0;
                if ($df > $corpusThreshold) return false; // corpus stopword
                // noise_exempt_promoted: a variant the swap just promoted IS
                // the user's intended word — filtering it back out for being
                // common in this field undoes the correction (promoted
                // "wikipedia", body df 195,616, survived only in title). The
                // corpus-stopword cutoff above still applies to it.
                $exempt = in_array($t, $originalTerms, true)
                    || ($rk['noise_exempt_promoted']
                        && in_array($t, $fuzzyPromotedTerms, true));
                if ($df > $fuzzyThreshold && !$exempt) return false;
                return true;
            }));
            if ($collectDiag) {
                $diagCut = array_values(array_diff($candidates, $optByField[$field]));
                if (!empty($diagCut)) $diagDropped[$field] = $diagCut;
            }
        }

        // --- Fetch postings ---
        // Two modes controlled by $twoPhase option:
        //
        // DEFAULT ($twoPhase = false): Fetch ALL fields' postings upfront.
        //   Complete — finds every doc mentioning query terms in any field.
        //   Slower for broad queries (the Wikipedia body postings table runs
        //   to tens of millions of rows).
        //
        // OPTIONAL ($twoPhase = true): Two-phase retrieval.
        //   Phase 1: fetch the light fields only, prune to the top phase1_limit
        //   candidates. Phase 2: fetch heavy_fields postings for survivors only.
        //   ~2x faster but can miss docs that mention terms only in a heavy
        //   field. Good for web search UIs where top 10-50 results matter most.

        $fieldPostings = [];

        // Heavy fields come from the corpus profile instead of a hardcoded
        // 'body'. Two-phase only makes sense when there is at least one heavy
        // field to defer AND at least one light field to seed candidates from;
        // otherwise fall back to standard single-phase retrieval.
        $heavyActive = array_values(array_intersect($heavyFields, array_keys($fields)));
        $lightFields = array_values(array_diff(array_keys($fields), $heavyActive));

        // Coverage-prune inputs computed ONCE — both retrieval branches
        // below prune candidates the same way (see pruneByCoverage()); each
        // branch used to recompute this list inline.
        $coverageTerms = array_unique(array_merge($required, $presynOptional));

        if ($twoPhase && !empty($heavyActive) && !empty($lightFields)) {
            // --- Two-phase mode ---
            // Phase 1: light fields (small, fast)
            foreach ($lightFields as $field) {
                $fetchTerms = array_unique(array_merge(
                    $allReqExpanded,
                    $excByField[$field],
                    $excPhraseWords,   // phrase-exclusion words per field
                    $optByField[$field]
                ));
                $fieldPostings[$field] = $this->fetchPostings($field, $fetchTerms);
            }

            // Collect candidates from light fields
            $allDocs = [];
            foreach ($fieldPostings as $postings) {
                foreach (array_keys($postings) as $docId) {
                    $allDocs[$docId] = true;
                }
            }

            // Phase 1 pruning: rank by coverage, keep top survivors.
            // Tuned via benchmarking (two-phase retrieval experiment):
            //   500  → 9.6s total, 18MB | 1000 → 12.4s, 20MB | 2000 → 17.2s, 24MB
            // The limit is config ('phase1_limit', default 1000). The prune loop
            // lives in pruneByCoverage(): it was once duplicated near-verbatim
            // in the standard branch below, so a fix to one prune (tie-breaking,
            // say) would silently miss the other.
            $prunedCandidates = $this->pruneByCoverage(
                $allDocs, $fieldPostings, $lightFields, $coverageTerms, $p1Limit
            );

            // Phase 2: heavy-field postings for survivors only
            $survivorIds = array_keys($prunedCandidates);
            foreach ($heavyActive as $hf) {
                $heavyTerms = array_unique(array_merge(
                    $allReqExpanded,
                    $excByField[$hf] ?? [],
                    $excPhraseWords,   // phrase-exclusion words
                    $optByField[$hf] ?? []
                ));
                $fieldPostings[$hf] = $this->fetchPostingsForDocs($hf, $heavyTerms, $survivorIds);
            }
        } else {
            // --- Standard mode: fetch all fields upfront ---
            foreach (array_keys($fields) as $field) {
                $fetchTerms = array_unique(array_merge(
                    $allReqExpanded,
                    $excByField[$field],
                    $excPhraseWords,   // phrase-exclusion words per field
                    $optByField[$field]
                ));
                $fieldPostings[$field] = $this->fetchPostings($field, $fetchTerms);
            }

            // Collect all candidate doc_ids
            $allDocs = [];
            foreach ($fieldPostings as $postings) {
                foreach (array_keys($postings) as $docId) {
                    $allDocs[$docId] = true;
                }
            }

            // Prune by original-term coverage (WAND-style).
            // The limit is config ('candidate_limit', default 5000); 0 = score
            // everything. See the twin pruneByCoverage() call in the two-phase
            // branch above.
            $prunedCandidates = $this->pruneByCoverage(
                $allDocs, $fieldPostings, array_keys($fields), $coverageTerms, $candLimit
            );
        }

        // --- Positional phrase data ---
        // Positions are fetched ONLY here: for the union of phrase words,
        // restricted to the pruned candidates (same targeted shape as
        // fetchPostingsForDocs). Never on the broad fetch above — blobs on
        // every posting row of a broad query would roughly double its memory.
        $phrasePositions = [];   // field => docId => term => [positions]
        if ($positionalPhrases) {
            $phraseTerms = array_values(array_unique(array_merge(
                empty($reqPhrases) ? [] : array_merge(...$reqPhrases),
                empty($excPhrases) ? [] : array_merge(...$excPhrases)
            )));
            $candidateIds = array_keys($prunedCandidates);
            if (!empty($phraseTerms) && !empty($candidateIds)) {
                foreach (array_keys($fields) as $pf) {
                    $phrasePositions[$pf] = $this->fetchPositionsForDocs($pf, $phraseTerms, $candidateIds);
                }
            }
        }

        if ($collectDiag) {
            $diagCand = ['matched' => count($allDocs), 'scored' => count($prunedCandidates),
                         'wildcard_excluded_docs' => count($excludedDocIds)];
        }

        // --- Pre-fetch stats for IDF/BM25 ---
        // $fieldStats and $termStatsByF already fetched and cached above.
        //
        // Also fetched when a title_field is configured, regardless of
        // algorithm — the title bonus and tiebreaker need the title's token
        // count from doclens now that doc_id is opaque (it used to be derived
        // from the doc_id string for free). Costs one batched PK fetch on
        // cover/freq/idf queries (~10-40ms at 5000 candidates); accepted
        // trade-off to keep their rankings identical.
        $titleActive = $titleField !== null && isset($fields[$titleField]);
        $docLengths = [];
        if ($titleActive || in_array($algo, ['bm25', 'bm25+cov', 'bm25f', 'rrf', 'dfr'], true)) {
            $docLengths = $this->fetchDocLengths(array_keys($prunedCandidates), array_keys($fields));
        }

        // --- Filter and score candidates ---
        // Hoist loop-invariant work out of the per-candidate loop. These
        // values depend only on the QUERY (term sets, boosts) — never on the
        // doc — but were recomputed for every candidate: at candidate_limit
        // 5000 × 3 fields that was 15,000 redundant array_merge+array_unique
        // passes per search, plus a boost re-sum inside every scoreDoc() call
        // (and twice per field per doc under RRF). A habit formed when the
        // corpus was ~80 Frankenstein pages; invisible until 283K docs.
        // Earlier all of this was computed inline inside the doc loop.
        $scoreTermsByField = [];
        $boostTotalByField = [];
        foreach (array_keys($fields) as $field) {
            $scoreTermsByField[$field] = array_unique(array_merge($allReqExpanded, $optByField[$field]));
            $tot = 0.0;
            foreach ($scoreTermsByField[$field] as $t) {
                $tot += $boosts[$t] ?? 1.0;
            }
            $boostTotalByField[$field] = $tot;
        }
        // BM25F scores one virtual document across all fields, so it needs the
        // UNION of the per-field term sets and the boost total over that union.
        $bm25fAllTerms = empty($scoreTermsByField)
            ? []
            : array_values(array_unique(array_merge(...array_values($scoreTermsByField))));
        $bm25fTotalBoost = 0.0;
        foreach ($bm25fAllTerms as $t) {
            $bm25fTotalBoost += $boosts[$t] ?? 1.0;
        }

        // Gated by the 'concept_coverage' ranking flag: per-field concept
        // structures for coverage accounting — denominator = Σ distinct
        // concept weights among the field's score terms, and the term→concept
        // map for scoreDoc. null when the flag is off → per-term accounting.
        $fieldConcepts     = [];
        $bm25fConceptTotal = null;
        // Skip the build when nothing expanded — every term is then its
        // own concept, so concept and per-term accounting are provably equal
        // and the structures would be pure overhead (+23ms measured on plain
        // multi-word queries). $expansionOccurred is final here: all expansion
        // sites (stem/syn/morph/wildcard/fuzzy) have run.
        if (!empty($rk['concept_coverage']) && $expansionOccurred) {
            $conceptTotalOver = function (array $terms) use ($conceptOf, $conceptWeight, $boosts): float {
                $seen = []; $tot = 0.0;
                foreach ($terms as $t) {
                    $key = $conceptOf[$t] ?? 't:' . $t;
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $tot += $conceptWeight[$key] ?? ($boosts[$t] ?? 1.0);
                }
                return $tot;
            };
            foreach (array_keys($fields) as $field) {
                $fieldConcepts[$field] = [
                    'of'     => $conceptOf,
                    'weight' => $conceptWeight,
                    'total'  => $conceptTotalOver($scoreTermsByField[$field]),
                ];
            }
            $bm25fConceptTotal = $conceptTotalOver($bm25fAllTerms);
        }

        $scores      = [];
        $fieldScores = [];
        $matches     = [];

        foreach (array_keys($prunedCandidates) as $docId) {
            if (!$this->passesRequired($docId, $reqExpansions, $fields, $fieldPostings)) continue;
            // In positional mode the word-level AND-in-field phrase
            // exclusion is REPLACED by true adjacency (passesPhrases below) —
            // passing $excPhrases to both would re-introduce the over-exclusion
            // the positional path exists to fix.
            if (!$this->passesExcluded($docId, $fields, $excByField, $fieldPostings, $excludedDocIds,
                                       $positionalPhrases ? [] : $excPhrases))  continue;
            if ($positionalPhrases
                && !$this->passesPhrases($docId, $reqPhrases, $excPhrases, array_keys($fields), $phrasePositions)) continue;

            $fieldScores[$docId] = [];
            $matches[$docId]     = [];

            if ($algo === 'bm25f') {
                // ── BM25F: single-pass multi-field scoring ──
                // Instead of scoring each field independently then averaging, compute
                // a virtual TF by blending field TFs, then apply BM25 once.
                //
                // For each term:
                //   tf_virtual = sum_f( weight_f * tf_f / (1 + b_f * (dl_f/avgdl_f - 1)) )
                //   score += IDF(term) * tf_virtual / (k1 + tf_virtual)
                //
                // b_f values: title fields should have low b (don't penalize short titles)
                // body fields keep standard b. This is the key BM25F insight — field-specific
                // length normalization.
                // Both hoisted above — query-invariant; they used to be rebuilt per doc.
                $allTerms         = $bm25fAllTerms;
                // concept denominator when the flag is on, per-term total otherwise
                $totalBoostWeight = $bm25fConceptTotal ?? $bm25fTotalBoost;
                $bm25fConceptMatched = [];

                $bm25fScore   = 0.0;
                $matchedBoost = 0.0;
                $allMatches   = [];

                // Field-specific b values: less aggressive normalization for short
                // fields like titles (short is normal there, shouldn't be penalized).
                // Tried: uniform b across all fields — title matches were too dependent
                // on title length. b_title=0.3 gives better results.
                //
                // Values come from the corpus profile ('field_b') instead of
                // hardcoding Wikipedia's field names. Unlisted fields use global b.
                // Earlier (hardcoded; now the Wikipedia corpus profile's field_b):
                // $fieldB = [
                //     'title'   => 0.3,  // low b: don't penalize short titles
                //     'opening' => 0.5,  // medium: openings vary in length
                //     'body'    => 0.75, // standard BM25 length normalization
                // ];
                $fieldB = $fieldBMap;

                foreach ($allTerms as $term) {
                    $boost = $boosts[$term] ?? 1.0;

                    // Compute virtual TF: weighted sum of field-normalized TFs
                    $tfVirtual = 0.0;
                    $termFound = false;

                    // IDF: use the max doc_freq across fields (most conservative)
                    $maxDf = 0;
                    $maxN  = 1;

                    foreach ($fields as $field => $weight) {
                        $tf = $fieldPostings[$field][$docId][$term] ?? 0;
                        if ($tf > 0) {
                            $termFound = true;
                            $allMatches[$field][] = $term;
                        }
                        $fS    = $fieldStats[$field] ?? ['total_docs' => 1, 'total_length' => 1];
                        $N     = max(1, $fS['total_docs']);
                        $avgdl = max(1, $fS['total_length'] / $N);
                        $dl    = $docLengths[$docId][$field] ?? 1;
                        // unlisted fields fall back to the ranking profile's global b
                        $bf    = $fieldB[$field] ?? $rk['b'];

                        // Field-normalized TF: adjust TF by field-specific length norm
                        $normTf = $tf / (1 + $bf * ($dl / $avgdl - 1));
                        $tfVirtual += $weight * $normTf;

                        $df = $termStatsByF[$field][$term] ?? 0;
                        if ($df > $maxDf) { $maxDf = $df; $maxN = $N; }
                    }

                    if (!$termFound) continue;

                    // BM25-style scoring with virtual TF
                    // Pre-computed IDF from the field with max df; fall back to log()
                    $idf = null;
                    foreach ($fields as $f => $_) {
                        if (($termStatsByF[$f][$term] ?? 0) === $maxDf && isset($this->termIdfByF[$f][$term])) {
                            $idf = $this->termIdfByF[$f][$term];
                            break;
                        }
                    }
                    $idf ??= log(($maxN + 0.5) / (max(1, $maxDf) + 0.5) + 1);
                    // BM25+ variant: add delta for lower bound
                    // k1/delta from the ranking profile
                    $tfScore = ($tfVirtual * ($rk['k1'] + 1)) / ($rk['k1'] + $tfVirtual) + $rk['delta'];
                    $bm25fScore += $idf * $tfScore * $boost;
                    // concept mode fills each concept's slot once (best form);
                    // per-term mode simply sums boosts
                    if ($bm25fConceptTotal !== null) {
                        $key = $conceptOf[$term] ?? 't:' . $term;
                        $cap = $conceptWeight[$key] ?? $boost;
                        $bm25fConceptMatched[$key] = max($bm25fConceptMatched[$key] ?? 0.0, min($boost, $cap));
                    } else {
                        $matchedBoost += $boost;
                    }
                }
                if ($bm25fConceptTotal !== null) {
                    $matchedBoost = array_sum($bm25fConceptMatched);
                }

                // Coverage boost (same curve as bm25+cov)
                $coverage = $totalBoostWeight > 0 ? $matchedBoost / $totalBoostWeight : 0.0;
                $bm25fScore *= (1.0 + $coverage * $coverage);

                foreach ($fields as $field => $weight) {
                    $fieldScores[$docId][$field] = 0; // BM25F doesn't have per-field scores
                    $matches[$docId][$field] = $allMatches[$field] ?? [];
                }
                $scores[$docId] = $bm25fScore;

            } elseif ($algo === 'rrf') {
                // ── RRF: Reciprocal Rank Fusion (Cormack et al., 2009) ──
                // Score each doc with both BM25 and coverage, then combine rankings.
                // We compute raw scores here and fuse rankings after the scoring loop.
                // Stores both component scores for later rank computation.
                $totalWeight   = array_sum(array_values($fields));
                $bm25Score     = 0.0;
                $coverScore    = 0.0;

                foreach ($fields as $field => $weight) {
                    $scoreTerms  = $scoreTermsByField[$field];   // hoisted, query-invariant
                    $docPostings = $fieldPostings[$field][$docId] ?? [];
                    $fStats      = $fieldStats[$field]   ?? ['total_docs' => 1, 'total_length' => 1];
                    $tStats      = $termStatsByF[$field]  ?? [];
                    $dl          = $docLengths[$docId][$field] ?? 1;

                    // DELIBERATE EXEMPTION: RRF's components always use
                    // per-term accounting (concepts = null), regardless of the
                    // concept_coverage flag. RRF consumes coverage as a RANK,
                    // not a magnitude — the variant-count is its discrimination
                    // signal, and concept mode collapses that ranking into ties
                    // whose arbitrary order pollutes the fusion (measured:
                    // fuzzy MRR .625->.417; 'volcanoe' target rank 1->9). The
                    // bm25 component has no coverage term either way.
                    $bm25Result = $this->scoreDoc($docPostings, $scoreTerms, 'bm25', $fStats, $tStats, $dl, $boosts, $field, $boostTotalByField[$field], $rk, null);
                    $coverResult = $this->scoreDoc($docPostings, $scoreTerms, 'cover', $fStats, $tStats, $dl, $boosts, $field, $boostTotalByField[$field], $rk, null);

                    $fieldScores[$docId][$field] = round($bm25Result['score'], 4);
                    $matches[$docId][$field]     = $bm25Result['matches'];
                    $bm25Score  += $weight * $bm25Result['score'];
                    $coverScore += $weight * $coverResult['score'];
                }

                // Store component scores temporarily; RRF fusion happens after the loop
                $scores[$docId] = [
                    'bm25'  => $totalWeight > 0 ? $bm25Score / $totalWeight : 0.0,
                    'cover' => $totalWeight > 0 ? $coverScore / $totalWeight : 0.0,
                ];

            } else {
                // ── Standard per-field scoring ──
                $weightedScore = 0.0;
                $totalWeight   = array_sum(array_values($fields));

                foreach ($fields as $field => $weight) {
                    $scoreTerms  = $scoreTermsByField[$field];   // hoisted, query-invariant
                    $docPostings = $fieldPostings[$field][$docId] ?? [];
                    $fStats      = $fieldStats[$field]   ?? ['total_docs' => 1, 'total_length' => 1];
                    $tStats      = $termStatsByF[$field]  ?? [];
                    $dl          = $docLengths[$docId][$field] ?? 1;

                    $result = $this->scoreDoc($docPostings, $scoreTerms, $algo, $fStats, $tStats, $dl, $boosts, $field, $boostTotalByField[$field], $rk, $fieldConcepts[$field] ?? null);

                    $fieldScores[$docId][$field] = round($result['score'], 4);
                    $matches[$docId][$field]     = $result['matches'];
                    $weightedScore              += $weight * $result['score'];
                }

                $scores[$docId] = $totalWeight > 0 ? ($weightedScore / $totalWeight) : 0.0;
            }

        }

        // --- RRF rank fusion ---
        // After scoring all candidates with both BM25 and coverage, compute
        // independent rankings for each, then combine using reciprocal rank formula.
        // k=60 is the standard value from the original paper.
        // Formula: rrf_score(d) = 1/(k + rank_bm25(d)) + 1/(k + rank_cover(d))
        if ($algo === 'rrf') {
            // 'rrf_k' ranking knob (60 by default — the paper's value)
            $rrfK = $rk['rrf_k'];
            // Extract component scores
            $bm25Scores = [];
            $coverScores = [];
            foreach ($scores as $docId => $components) {
                $bm25Scores[$docId] = $components['bm25'];
                $coverScores[$docId] = $components['cover'];
            }
            // Rank each independently (descending score)
            arsort($bm25Scores);
            arsort($coverScores);

            $bm25Ranks = [];
            $rank = 1;
            foreach (array_keys($bm25Scores) as $docId) {
                $bm25Ranks[$docId] = $rank++;
            }
            $coverRanks = [];
            $rank = 1;
            foreach (array_keys($coverScores) as $docId) {
                $coverRanks[$docId] = $rank++;
            }

            // Compute RRF score for each doc
            foreach (array_keys($scores) as $docId) {
                $rrfScore = 1.0 / ($rrfK + ($bm25Ranks[$docId] ?? PHP_INT_MAX))
                          + 1.0 / ($rrfK + ($coverRanks[$docId] ?? PHP_INT_MAX));
                $scores[$docId] = $rrfScore;
            }
        }

        // --- Density-based proximity boost ---
        // Approximates proximity scoring without positional data by using term frequency
        // and field length as a proxy for term distance. If two query terms both appear
        // frequently in a short field, they are likely close together.
        //
        // Formula per term pair per field:
        //   expected_min_dist = field_length / (freq_a + freq_b)
        //   pair_boost = 1 / (1 + expected_min_dist)
        //
        // Averaged across all query term pairs, then applied as a multiplicative boost.
        // Only for multi-term queries (2+ matched terms) on BM25-family algorithms where
        // we have field length data. Title fields naturally produce high proximity because
        // they're short (avg ~3 tokens), but this is intentional — terms in a title ARE
        // close together, which should be rewarded.
        //
        // Inspired by Lucene's span queries and Elasticsearch's proximity boosting, but
        // uses a statistical approximation instead of exact positions. The key insight:
        // no need for a positional index if you accept a density-based heuristic.
        //
        // Trade-off: this can over-estimate proximity in long docs where terms cluster
        // in one section but are far from each other overall. However, BM25's length
        // normalization already penalizes long docs, so the effect is bounded.
        // Use ORIGINAL query terms (pre-expansion) for proximity. Expanded wildcard/fuzzy
        // terms would create O(n²) pairs and are meaningless for proximity anyway.
        // E.g., "prog*" expands to 100 terms — we don't care about proximity between
        // "program" and "progress". We care about proximity between the user's actual words.
        // Tried: using $allReqExpanded — caused 22s regression on wildcard queries
        // because 100 expanded terms × 100 = 4950 pairs × 3509 docs × 3 fields = 52M iterations.
        $proxQueryTerms = array_unique(array_merge($required, $presynOptional));
        // Gated on 'proximity_weight' > 0 so bag-of-words corpora (tags)
        // can disable the whole block.
        if ($rk['proximity_weight'] > 0
            && count($proxQueryTerms) >= 2
            && in_array($algo, ['bm25', 'bm25+cov', 'bm25f', 'dfr'], true)
            && !empty($docLengths)
        ) {
            // Build all unique pairs of original query terms
            $termPairs = [];
            for ($i = 0, $n = count($proxQueryTerms); $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $termPairs[] = [$proxQueryTerms[$i], $proxQueryTerms[$j]];
                }
            }
            if (!empty($termPairs)) {
                foreach (array_keys($scores) as $docId) {
                    $proxSum = 0.0;
                    $proxPairs = 0;
                    foreach ($fields as $field => $weight) {
                        $dl = $docLengths[$docId][$field] ?? 0;
                        if ($dl < 2) continue;
                        $docP = $fieldPostings[$field][$docId] ?? [];
                        foreach ($termPairs as [$ta, $tb]) {
                            $fa = $docP[$ta] ?? 0;
                            $fb = $docP[$tb] ?? 0;
                            if ($fa === 0 || $fb === 0) continue;
                            // Expected minimum distance between any occurrence of ta and tb:
                            // If both appear frequently in a short doc, distance is small.
                            $expectedMinDist = $dl / ($fa + $fb);
                            // Weight by field weight (title proximity matters more)
                            $proxSum += $weight / (1.0 + $expectedMinDist);
                            $proxPairs++;
                        }
                    }
                    if ($proxPairs > 0) {
                        // Normalize by number of pairs, scale to a gentle boost (1.0 - ~1.3)
                        // Tried: raw proxSum — too strong, overwhelmed other signals.
                        // Tried: no normalization — multi-term queries got unfairly boosted.
                        // This formula gives a gentle, proportional boost.
                        $avgProx = $proxSum / $proxPairs;
                        // 'proximity_weight' knob (0.3 by default)
                        $proximityBoost = 1.0 + $rk['proximity_weight'] * min(1.0, $avgProx);
                        $scores[$docId] *= $proximityBoost;
                    }
                }
            }
        }

        // --- Title exact match bonus ---
        // When the doc title closely matches the query, apply a multiplicative bonus.
        // This is similar to Sphinx's SPH04 ranker and MeiliSearch's "exactness" criterion.
        //
        // Why: Without this, BM25 length normalization can cause "Albert Einstein Square"
        // to outrank "Albert Einstein" (shorter article = higher BM25 density), and
        // coverage gives all single-word matches the same 100% score.
        //
        // Tried: additive bonus (score + N) — failed because the bonus had to be tuned
        // per-algorithm. Multiplicative works across all algos since it preserves ordering
        // within the same tier.
        //
        // The first version was a flat 2.0x for full match, 1.3x for half — not enough for BM25 because
        // short articles about "Albert Einstein Square" had higher opening/body density
        // that overwhelmed the title advantage. The 2.0/1.83 spread between perfect
        // and partial wasn't big enough.
        //
        // Current: lenRatio^2 for steeper differentiation. Perfect title match
        // (title = query) gets up to 3.0x. Title with extra words gets progressively less.
        // Also added tiebreaker: when scores are equal (common with coverage/IDF on
        // single-word queries), shorter titles win (more likely the canonical article).
        //
        // We use the original query terms (pre-fuzzy, pre-synonym) to check title match,
        // so fuzzy expansions don't count as "exact title match".
        // Exception: fuzzy-promoted terms (from the noise filter) ARE included. When the
        // user typed "einstien" and we detected it's a rare typo for "einstein", we want
        // the title bonus to fire for "Einstein" in title. Without this, "Albert Einstein"
        // gets no title bonus on the "einstien" query even though we promoted "einstein".
        // The bonus reads the doc's INDEXED title-field postings (already in
        // memory from the scoring fetch) and its token count from doclens, instead
        // of parsing the doc_id string. Consequences:
        //   - doc_id is opaque; any corpus works (integer ids, duplicate titles)
        //   - matching uses the real tokenizer, so punctuated titles like
        //     "Python (programming language)" now earn the bonus correctly
        //     (explode(' ') produced unmatchable tokens like "(programming")
        //   - zero extra I/O: both inputs are byproducts of scoring
        // Earlier (doc_id-as-title):
        // if (!empty($queryTermsLower) && isset($fields['title'])) {
        //     foreach (array_keys($scores) as $docId) {
        //         $titleTokens = array_map('mb_strtolower', explode(' ', $docId));
        //         ... count matches against $titleTokens ...
        //         $lenRatio = count($queryTermsLower) / max(1, count($titleTokens));
        //         ... same bonus formula ...
        //     }
        // }
        // The bonus is CONCEPT-based, not term-based. Each word the user
        // typed is one concept; its stem variants are FORMS of that concept,
        // not concepts of their own. A title matching ANY form fills the slot:
        // query "+wolves" (stemmed) gives the "Wolf" article a FULL title
        // match — under the earlier flat term list, "Wolf" earned no bonus at all
        // for required terms (the originals-only list didn't contain "wolf"),
        // and only a diluted 1/3 ratio for optional ones (wolves+wolf+wolfe
        // each counted as a separate query word). Fuzzy-promoted variants
        // remain their own concepts (no origin tracking — same as before).
        // This is the title-bonus slice of the wider "coverage slots" idea;
        // the scoring-side denominators (scoreDoc/BM25F coverage) still count
        // variants individually unless concept_coverage is on (see
        // RANKING_DEFAULTS).
        // Earlier:
        // $queryTermsLower = array_map('mb_strtolower', array_merge($required, $presynOptional, $fuzzyPromotedTerms));
        // ...foreach ($queryTermsLower as $qt) { if (isset($titlePost[$qt])) $titleMatched++; }
        // ...$matchRatio = $titleMatched / count($queryTermsLower);
        $titleConcepts = [];
        foreach ($required as $t) {
            $titleConcepts['r:' . $t] = $reqStemGroups[$t] ?? [$t];
        }
        foreach ($presynOptional as $t) {
            if (isset($optVariantSet[$t])) continue;   // a form, not a concept
            $titleConcepts['o:' . $t] = $optStemGroups[$t] ?? [$t];
        }
        foreach ($fuzzyPromotedTerms as $t) {
            // title_promoted_as_form: the promoted variant is a spelling of
            // the word it corrects, not a new query word — count it as a FORM
            // filling the origin's slot ("rukh OR rush in the title"). As its
            // own concept it inflates the denominator: promoted "rush" made
            // "shah rukh khan" a 4-concept query, so the exact-title article
            // matched 3/4 and dropped from the 3.0x full bonus to 1.3x.
            $origin = $fuzzyPromotedOrigin[$t] ?? null;
            if ($rk['title_promoted_as_form'] && $origin !== null) {
                if (isset($titleConcepts['o:' . $origin])) {
                    $titleConcepts['o:' . $origin][] = $t;
                    continue;
                }
                if (isset($titleConcepts['r:' . $origin])) {
                    $titleConcepts['r:' . $origin][] = $t;
                    continue;
                }
            }
            $titleConcepts['p:' . $t] = [$t];
        }
        $conceptCount = count($titleConcepts);

        if ($conceptCount > 0 && $titleActive) {
            $titlePostings = $fieldPostings[$titleField] ?? [];
            foreach (array_keys($scores) as $docId) {
                $titlePost = $titlePostings[$docId] ?? [];
                if (empty($titlePost)) continue;
                // Count how many query CONCEPTS appear in the title field
                $titleMatched = 0;
                foreach ($titleConcepts as $forms) {
                    foreach ($forms as $f) {
                        if (isset($titlePost[$f])) { $titleMatched++; break; }
                    }
                }
                if ($titleMatched === 0) continue;
                $matchRatio = $titleMatched / $conceptCount;

                if ($matchRatio >= 1.0) {
                    // All query terms found in title.
                    // lenRatio measures how much of the title IS the query:
                    //   "Albert Einstein" for query "albert einstein" → lenRatio = 1.0
                    //   "Albert Einstein Square" → lenRatio = 0.67
                    //   "Albert Einstein Memorial in Washington" → lenRatio = 0.4
                    // Title length = indexed token count from doclens (earlier
                    // count(explode(' ', $docId))). Clamped to 1.0: duplicate query
                    // terms could otherwise push the ratio above 1 (the earlier word-count
                    // denominator had the same theoretical quirk).
                    $titleLen = $docLengths[$docId][$titleField] ?? 0;
                    if ($titleLen < 1) $titleLen = count($titlePost);
                    // concepts, not raw terms
                    $lenRatio = min(1.0, $conceptCount / max(1, $titleLen));
                    // Earlier: bonus = 1.5 + 0.5 * lenRatio    (max 2.0x, spread too narrow)
                    // Now:     bonus = 1.5 + 1.5 * lenRatio^2  (max 3.0x, spreads wider)
                    // lenRatio=1.0 → 3.0x, 0.67 → 2.17x, 0.5 → 1.875x, 0.33 → 1.66x
                    // The curve constants are ranking knobs (title_bonus_base/span/
                    // half/partial); the defaults reproduce the formula above.
                    $bonus = $rk['title_bonus_base'] + $rk['title_bonus_span'] * ($lenRatio * $lenRatio);
                    $scores[$docId] *= $bonus;
                } elseif ($matchRatio >= 0.5) {
                    $scores[$docId] *= $rk['title_bonus_half'];
                } else {
                    $scores[$docId] *= $rk['title_bonus_partial'];
                }
            }
        }

        // --- Score normalization ---
        // Normalize scores to 0-100 range so different algorithms are comparable.
        // Without this, BM25 gives scores in 5-70, coverage in 0-100, IDF in 5-25,
        // RRF in 0.01-0.1 — confusing for users comparing algorithms.
        //
        // Uses simple max-normalization: score_normalized = (score / max_score) * 100.
        // The top result always gets 100, and other results are relative to it.
        // This preserves ranking order exactly while making scores human-readable.
        //
        // Tried: z-score normalization — required computing mean and stddev, and
        // scores could be negative. Max-normalization is simpler and always positive.
        // Tried: min-max normalization — bottom result gets 0, but "0" implies no
        // relevance which is misleading. Max-normalization avoids this.
        if (!empty($scores)) {
            $maxScore = max($scores);
            if ($maxScore > 0) {
                foreach ($scores as &$s) {
                    $s = ($s / $maxScore) * 100.0;
                }
                unset($s);
            }
        }

        // --- Sort and paginate ---
        // Sort by score descending, then by title length ascending as tiebreaker.
        // When two docs have equal scores (common with coverage/IDF on single-word
        // queries), shorter titles are more likely the canonical article:
        // "Water" over "Water pollution", etc.
        // Tried: alphabetical tiebreaker — arbitrary and unhelpful.
        // Title length is a better proxy for "specificity to the query".
        //
        // Title length = doclens token count (doc_id is opaque). Coarser
        // than the earlier character count — 'USA' vs 'Water' are both one
        // token and fall through to the doc_id comparison — but free, and it
        // preserves the "canonical article" intent. No title_field configured →
        // straight to the deterministic doc_id comparison.
        // Earlier (doc_id-as-title, character length):
        // uksort($scores, function($a, $b) use ($scores) {
        //     $cmp = $scores[$b] <=> $scores[$a]; // descending score
        //     if ($cmp !== 0) return $cmp;
        //     $cmp = mb_strlen($a) <=> mb_strlen($b); // ascending title length
        //     if ($cmp !== 0) return $cmp;
        //     return strcmp($a, $b); // alphabetical tiebreaker for deterministic ordering
        // });
        uksort($scores, function($a, $b) use ($scores, $docLengths, $titleField, $titleActive) {
            $cmp = $scores[$b] <=> $scores[$a]; // descending score
            if ($cmp !== 0) return $cmp;
            if ($titleActive) {
                $la = $docLengths[$a][$titleField] ?? PHP_INT_MAX;
                $lb = $docLengths[$b][$titleField] ?? PHP_INT_MAX;
                $cmp = $la <=> $lb; // ascending title token count
                if ($cmp !== 0) return $cmp;
            }
            return $a <=> $b; // deterministic ordering (int or string doc ids)
        });
        $positiveIds = array_keys(array_filter($scores, fn($s) => $s > 0));
        $total       = count($positiveIds);
        $pagedIds    = array_slice($positiveIds, ($page - 1) * $perPage, $perPage);

        // --- Fetch titles + snippets for paged results ---
        // Load display data for the current page only (typically 10-20 docs):
        // one small IN() query + string operations.
        //
        // Design notes:
        //   - documents table is OPTIONAL (introspected once, cached). Absent →
        //     empty snippets, title falls back to the doc_id string.
        //   - title column (if present) is returned per hit — with opaque doc
        //     ids this is the only place display titles come from.
        //   - snippet is truncated and HTML-ESCAPED before <mark> highlighting.
        //     Earlier code regexed <mark> into raw opening text; safe in the UI only
        //     because index.php ignored it and re-built its own escaped snippet
        //     (duplicate fetch + latent XSS for any other consumer).
        $snippets = [];
        $titles   = [];
        $docCols  = $this->documentsColumns();
        if (!empty($pagedIds) && in_array('opening', $docCols, true)) {
            $hasTitleCol = in_array('title', $docCols, true);
            $cols = $hasTitleCol ? 'doc_id, title, opening' : 'doc_id, opening';
            $ph   = implode(',', array_fill(0, count($pagedIds), '?'));
            $stmt = $this->db->prepare("SELECT $cols FROM documents WHERE doc_id IN ($ph)");
            $stmt->execute($pagedIds);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $snippets[$row['doc_id']] = $row['opening'];
                if ($hasTitleCol) $titles[$row['doc_id']] = $row['title'];
            }
        }

        // Collect all matched terms across fields for highlighting
        $hits = [];
        foreach ($pagedIds as $docId) {
            $snippet = (string)($snippets[$docId] ?? '');

            if ($snippet !== '') {
                // Truncate first (on raw text, so the cut is in characters).
                // 'snippet_length' knob (SNIPPET_LENGTH const is its default).
                $snipLen = (int)$rk['snippet_length'];
                if ($snipLen > 0 && mb_strlen($snippet) > $snipLen) {
                    $snippet = mb_substr($snippet, 0, $snipLen) . '…';
                }

                $matchTerms = [];
                foreach ($matches[$docId] ?? [] as $fieldMatches) {
                    foreach ($fieldMatches as $term) $matchTerms[$term] = true;
                }
                // Highlight by TOKENIZING the snippet, not by regexing
                // the matched terms into it. Matched terms are index
                // vocabulary — folded/apostrophe-stripped — so a regex for
                // "sao" or "dont" never found "São" or "don't" in the raw
                // text: exactly the words folding fixes went unhighlighted.
                // Now each word of the snippet is normalized with the same
                // rules the query went through and marked when it lands in
                // the match set. Escaping happens per segment, so markup can
                // never be mistaken for text. ASCII behaviour is unchanged.
                // Earlier (regex on escaped text; ASCII-only lookarounds):
                //   $pattern = '/(?<![a-z])(' . implode('|', $escaped) . ')(?![a-z])/iu';
                //   $snippet = preg_replace($pattern, '<mark>$1</mark>', $snippet);
                if (empty($matchTerms)) {
                    $snippet = htmlspecialchars($snippet, ENT_QUOTES);
                } else {
                    $parts = preg_split("/([\p{L}\p{N}\p{M}']+)/u", $snippet, -1, PREG_SPLIT_DELIM_CAPTURE);
                    $out = '';
                    foreach ($parts as $i => $seg) {
                        if ($seg === '') continue;
                        $esc = htmlspecialchars($seg, ENT_QUOTES);
                        if ($i % 2 === 1) {                     // captured word
                            $norm = $this->normalizeQueryTerm($seg);
                            if (count($norm) === 1 && isset($matchTerms[$norm[0]])) {
                                $esc = '<mark>' . $esc . '</mark>';
                            }
                        }
                        $out .= $esc;
                    }
                    $snippet = $out;
                }
            }

            $hits[$docId] = [
                'score'        => round($scores[$docId], 4),
                'title'        => (string)($titles[$docId] ?? $docId),
                'field_scores' => $fieldScores[$docId],
                'matches'      => $matches[$docId],
                'snippet'      => $snippet,
            ];
        }

        $out = [
            'hits'     => $hits,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => max(1, (int)ceil($total / $perPage)),
            // The RESOLVED algorithm — when the caller asked for 'auto',
            // this is what auto actually chose (the UI shows "AUTO → BM25F").
            'algo'     => $algo,
        ];
        if ($collectDiag) {
            $out['diag'] = [
                'query' => [
                    'typed_optional' => $typedOptional,
                    'required'       => $required,
                    'excluded'       => $excluded,
                    'req_phrases'    => $reqPhrases,
                    'exc_phrases'    => $excPhrases,
                ],
                'algo' => ['requested' => $requestedAlgo, 'resolved' => $algo]
                          + ($diagAuto ?? []),
                'expansions' => $diagExp,
                'wildcards'  => array_map(
                    fn($set) => ['count' => count($set),
                                 'terms' => array_slice(array_keys($set), 0, 10)],
                    $diagWild),
                'typo_swaps' => $diagSwaps,
                'dropped'    => $diagDropped,
                'candidates' => ($diagCand ?? []) + ['passed_filters' => count($scores)],
                'positional_phrases' => $positionalPhrases,
            ];
        }
        return $out;
    }

    // =========================================================================
    // Filtering
    // =========================================================================

    /**
     * WAND-style candidate pruning: when more than $limit docs match, keep the
     * $limit best by how many of the user's ORIGINAL query terms they contain
     * (expanded wildcard/fuzzy terms don't count — coverage of the user's
     * actual words is the relevance proxy). $limit <= 0 disables pruning.
     *
     * Returns [docId => true, ...] — same shape as the $allDocs input.
     *
     * Known limitation (pre-existing, unchanged by the extraction): docs TIED
     * on coverage at the cut boundary survive in postings-fetch order, which
     * is doc-id order — arbitrary but deterministic for a given index. Fixing
     * tie-breaking now only needs to happen HERE.
     *
     * Extracted from two near-identical ~25-line inline blocks (two-phase
     * branch pruning to phase1_limit over light fields; standard branch pruning
     * to candidate_limit over all fields). The blocks had already started to
     * drift cosmetically; a behavioral fix to one would have silently missed
     * the other.
     */
    private function pruneByCoverage(
        array $allDocs,
        array $fieldPostings,
        array $fieldList,
        array $originalTerms,
        int $limit
    ): array {
        if ($limit <= 0 || count($allDocs) <= $limit || count($originalTerms) === 0) {
            return $allDocs;
        }
        $docCoverage = [];
        foreach (array_keys($allDocs) as $docId) {
            $matched = 0;
            foreach ($originalTerms as $ot) {
                foreach ($fieldList as $field) {
                    if (isset($fieldPostings[$field][$docId][$ot])) {
                        $matched++;
                        break;
                    }
                }
            }
            $docCoverage[$docId] = $matched;
        }
        arsort($docCoverage);
        return array_fill_keys(array_keys(array_slice($docCoverage, 0, $limit, true)), true);
    }

    /**
     * Each key in $reqExpansions must have at least one expanded term
     * present somewhere across all fields.
     */
    private function passesRequired(
        string|int $docId,   // doc ids are opaque — int or string
        array $reqExpansions,
        array $fields,
        array $fieldPostings
    ): bool {
        foreach ($reqExpansions as $expansions) {
            $found = false;
            foreach (array_keys($fields) as $field) {
                foreach ($expansions as $exp) {
                    if (isset($fieldPostings[$field][$docId][$exp])) {
                        $found = true;
                        break 2;
                    }
                }
            }
            if (!$found) return false;
        }
        return true;
    }

    // $excludedDocIds — complete doc-id set from excluded WILDCARDS,
    // collected by SQL range scan (see excludedDocIdsForPrefixes). Exact
    // excluded terms still go through the postings check below.
    // $excPhrases — excluded-phrase AND groups: a doc is excluded when
    // it contains ALL of a group's words in the SAME field. See parseQuery's
    // asymmetry-fix note (+phrase always decomposed AND; -phrase used to fall
    // into the flat OR list below).
    private function passesExcluded(
        string|int $docId,   // doc ids are opaque — int or string
        array $fields,
        array $excByField,
        array $fieldPostings,
        array $excludedDocIds = [],
        array $excPhrases = []
    ): bool {
        if (isset($excludedDocIds[$docId])) return false;
        foreach (array_keys($fields) as $field) {
            foreach ($excByField[$field] as $excTerm) {
                if (isset($fieldPostings[$field][$docId][$excTerm])) return false;
            }
        }
        foreach ($excPhrases as $group) {
            foreach (array_keys($fields) as $field) {
                $all = true;
                foreach ($group as $w) {
                    if (!isset($fieldPostings[$field][$docId][$w])) { $all = false; break; }
                }
                if ($all) return false;
            }
        }
        return true;
    }

    /**
     * Complete doc-id set matching any of the given prefixes in any of the
     * given fields — the exclusion mechanism for -prefix* wildcards. A prefix's doc set comes
     * from ONE index range scan per field (SELECT DISTINCT doc_id), entirely
     * SQL-side: no term expansion, no cap, no posting rows materialized in
     * PHP. Even a pathological -s* (95K terms, ~3.6M posting rows) is just a
     * B-tree range walk returning distinct ids.
     * Returns [docId => true, ...]; empty when there are no excluded wildcards.
     */
    private function excludedDocIdsForPrefixes(array $prefixes, array $fields): array
    {
        $out = [];
        foreach ($prefixes as $prefix) {
            if ($prefix === '') continue;
            $upper = self::prefixUpperBound($prefix);
            foreach ($fields as $field) {
                $sql = "SELECT DISTINCT doc_id FROM postings_$field WHERE term >= :p"
                     . ($upper !== null ? " AND term < :ub" : "");
                $stmt = $this->db->prepare($sql);
                $stmt->bindValue(':p', $prefix);
                if ($upper !== null) $stmt->bindValue(':ub', $upper);
                $stmt->execute();
                while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                    $out[$row[0]] = true;
                }
            }
        }
        return $out;
    }

    /**
     * Positional phrase filter. A doc passes when every required phrase
     * occurs ADJACENTLY in at least one field, and no excluded phrase occurs
     * adjacently in any field. Only called in positional mode (every active
     * field has a pos column); non-positional indexes keep word-level
     * (all-words, any-order) semantics.
     */
    private function passesPhrases(
        string|int $docId,
        array $reqPhrases,
        array $excPhrases,
        array $fields,
        array $phrasePositions
    ): bool {
        foreach ($reqPhrases as $group) {
            $found = false;
            foreach ($fields as $field) {
                $posByTerm = $phrasePositions[$field][$docId] ?? [];
                if (!empty($posByTerm) && self::phraseAdjacent($posByTerm, $group)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) return false;
        }
        foreach ($excPhrases as $group) {
            foreach ($fields as $field) {
                $posByTerm = $phrasePositions[$field][$docId] ?? [];
                if (!empty($posByTerm) && self::phraseAdjacent($posByTerm, $group)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Does the ordered token $group occur consecutively in one doc+field,
     * given each term's decoded position list? Walk the first word's
     * positions p and require word i at p+i — repeated words in a phrase
     * ("new new york") work because the same position set must then contain
     * both p and p+1. Position lists are short (avg ~1.8/posting), so the
     * scan is trivially cheap per candidate.
     */
    private static function phraseAdjacent(array $posByTerm, array $group): bool
    {
        $first = $posByTerm[$group[0]] ?? [];
        if (empty($first)) return false;
        $n = count($group);
        $sets = [];
        for ($i = 1; $i < $n; $i++) {
            $p = $posByTerm[$group[$i]] ?? [];
            if (empty($p)) return false;
            $sets[$i] = array_flip($p);
        }
        foreach ($first as $p) {
            for ($i = 1; $i < $n; $i++) {
                if (!isset($sets[$i][$p + $i])) continue 2;
            }
            return true;
        }
        return false;
    }

    // =========================================================================
    // Scoring
    // =========================================================================

    // $field lets scoreDoc look up pre-computed IDF from
    // $this->termIdfByF[$field][$term]. This method has no fallback
    // formula: Builder::computeStats() is where IDF is defined (the engine
    // once carried a third copy of log((N+.5)/(df+.5)+1), the build script a
    // second — the same private-copy drift the tokenizer taught us about).
    // Every term with postings has a termstats row, so a miss can only mean
    // a term absent from this field: 0. (The BM25F path in search() keeps
    // one log() fallback for a termstats miss on its max-df field; by the
    // same argument it should never fire.)
    // $totalBoostWeight — the boost total over $scoreTerms is query-invariant,
    // but this method used to re-sum it on EVERY call (once per doc per field,
    // twice under RRF). Callers pass the precomputed value; the sentinel -1.0
    // keeps the self-computing behavior for any caller that doesn't have it.
    //
    // $rk (resolved ranking profile) — k1/b/delta were once read from instance
    // properties, which couldn't honor per-search 'ranking' overrides. Empty
    // array = fall back to the instance profile.
    private function scoreDoc(
        array $docPostings,
        array $scoreTerms,
        string $algo,
        array $fStats,
        array $tStats,
        int $docLength,
        array $boosts = [],
        string $field = '',
        float $totalBoostWeight = -1.0,
        array $rk = [],
        ?array $concepts = null
    ): array {
        // $concepts = ['of' => term→concept, 'weight' => concept→weight,
        // 'total' => Σ concept weights] — concept-based coverage accounting
        // (see the 'concept_coverage' ranking flag). null = per-term (default).
        if (empty($rk)) $rk = $this->ranking;
        if (empty($scoreTerms) || empty($docPostings)) {
            return ['score' => 0.0, 'matches' => []];
        }

        $N     = max(1, $fStats['total_docs']);
        $avgdl = max(1, $fStats['total_length'] / $N);

        // Total boost weight across all search terms (for cover normalisation).
        // Earlier this was always summed here, per call (see the note above).
        // In concept mode the denominator is the concept-weight total.
        if ($concepts !== null) {
            $totalBoostWeight = $concepts['total'];
        } elseif ($totalBoostWeight < 0) {
            $totalBoostWeight = 0.0;
            foreach ($scoreTerms as $t) {
                $totalBoostWeight += $boosts[$t] ?? 1.0;
            }
        }

        $score          = 0.0;
        $matchedTerms   = [];
        $matchedBoost   = 0.0;
        $conceptMatched = [];   // concept key => best matched form boost

        foreach ($scoreTerms as $term) {
            $tf = $docPostings[$term] ?? 0;
            if ($tf === 0) continue;

            $matchedTerms[] = $term;
            $boost = $boosts[$term] ?? 1.0;
            // Concept mode — a matched form fills its concept's slot at the
            // form's boost, capped at the concept's own weight; matching
            // several forms of one concept fills the slot once (best form).
            // Per-term mode counts every form.
            if ($concepts !== null) {
                $key = $concepts['of'][$term] ?? 't:' . $term;
                $cap = $concepts['weight'][$key] ?? $boost;
                $conceptMatched[$key] = max($conceptMatched[$key] ?? 0.0, min($boost, $cap));
            } else {
                $matchedBoost += $boost;
            }

            switch ($algo) {
                case 'cover':
                    $score += $boost;
                    break;

                case 'freq':
                    // freq_tf_log: log-damp raw counts so a long doc repeating
                    // one query word 50 times (~4 after damping) can't drown a
                    // doc matching every word once. Keeps freq's real strength
                    // — accumulating evidence across a typo's variants.
                    $score += ($rk['freq_tf_log'] ? log(1 + $tf) : $tf) * $boost;
                    break;

                case 'idf':
                    // Pre-computed at build time (Builder::computeStats)
                    // Earlier: $idf = log(($N + 0.5) / (max(1, $tStats[$term] ?? 1) + 0.5) + 1);
                    $idf = $this->termIdfByF[$field][$term] ?? 0.0;
                    $score += $idf * $boost;
                    break;

                case 'bm25':
                case 'bm25+cov':
                    // Pre-computed at build time (Builder::computeStats)
                    // Earlier: $idf = log(($N + 0.5) / (max(1, $tStats[$term] ?? 1) + 0.5) + 1);
                    $idf = $this->termIdfByF[$field][$term] ?? 0.0;
                    // k1/b come from the ranking profile (constructor or per-search override)
                    $tfNorm = ($tf * ($rk['k1'] + 1))
                            / ($tf + $rk['k1'] * (1 - $rk['b'] + $rk['b'] * $docLength / $avgdl));
                    // BM25+ (Lv & Zhai 2011): add delta to give a lower bound on TF contribution.
                    // Standard BM25: very long docs get near-zero tfNorm even with tf>0.
                    // BM25+ ensures every term occurrence contributes at least delta * idf.
                    // Earlier (classic BM25): $score += $idf * $tfNorm * $boost;
                    $score += $idf * ($tfNorm + $rk['delta']) * $boost;
                    break;

                case 'dfr':
                    // DFR InL2: Inverse document frequency model, Laplace after-effect,
                    // Normalization 2. From Amati & Van Rijsbergen (2002), used in
                    // Terrier and Lucene/Solr.
                    //
                    // Step 1: Normalize TF by document length (Normalization 2).
                    // Uses log2(1 + avgdl/dl) to dampen TF in long docs and boost in short.
                    // Unlike BM25's linear interpolation (the b parameter), this uses a
                    // logarithmic curve — gentler normalization, less aggressive on extremes.
                    $tfn = $tf * log(1 + $avgdl / max(1, $docLength)) / log(2);
                    // Step 2: Information content — how surprising is this term frequency?
                    // Based on inverse document frequency: rarer terms carry more info.
                    // The (tfn + 0.5)/(N+1) models the probability of seeing this many
                    // occurrences by chance. -log2 converts probability to bits of info.
                    $info = max(0, -log(($tfn + 0.5) / ($N + 1)) / log(2));
                    // Step 3: Laplace after-effect — how confident are we?
                    // tfn/(tfn+1) → 0.5 for tfn=1, 0.67 for tfn=2, approaches 1.0.
                    // Low TF = low confidence (could be noise), high TF = high confidence.
                    $risk = $tfn / ($tfn + 1);
                    $score += $info * $risk * $boost;
                    break;
            }
        }

        // finalize concept accounting before the coverage tails
        if ($concepts !== null) {
            $matchedBoost = array_sum($conceptMatched);
        }

        switch ($algo) {
            case 'cover':
                // Uses $matchedBoost — identical to the earlier Σ-per-term
                // $score in per-term mode (cover's loop added $boost to both),
                // concept-aware when the flag is on.
                $score = $totalBoostWeight > 0 ? ($matchedBoost / $totalBoostWeight) * 100.0 : 0.0;
                break;
            case 'bm25+cov':
                // BM25 * (1 + coverage^2). A doc matching 4/4 terms gets 2x (1+1²),
                // 2/4 gets 1.25x (1+0.5²). Linear coverage, BM25 * (1 + coverage),
                // was benchmarked alongside this and lost: squared punishes partial
                // matches harder and surfaces the "obvious" result more often.
                $coverage = $totalBoostWeight > 0 ? $matchedBoost / $totalBoostWeight : 0.0;
                $score *= (1.0 + $coverage * $coverage);
                break;
        }

        return ['score' => $score, 'matches' => array_values($matchedTerms)];
    }

    // =========================================================================
    // Query parsing
    // =========================================================================

    /**
     * Parses query string into buckets with boosts and wildcard prefixes.
     *
     * Returns:
     *   required  [term, ...]
     *   excluded  [term, ...]
     *   optional  [term, ...]
     *   boosts    [term => float]
     *   req_wild  [prefix => boost]
     *   exc_wild  [prefix => boost]
     *   opt_wild  [prefix => boost]
     */
    private function parseQuery(string $input): array
    {
        $required = [];
        $excluded = [];
        $optional = [];
        $boosts   = [];
        $req_wild = [];
        $exc_wild = [];
        $opt_wild = [];

        $requiredPhrases = [];
        $excludedPhrases = [];

        $input = mb_strtolower($input);
        $input = preg_replace('/\s+/u', ' ', trim($input));
        $input = preg_replace("/[^\p{L}\p{N}\p{M}\s'\"*+^.-]+/u", '', $input);

        preg_match_all('/((?<=\s|^)(\+|-)"[^"]*"|"[^"]*"|\S+)/', $input, $m);

        foreach ($m[0] as $token) {
            // Quoted phrases (with optional +/-)
            if (preg_match('/^\+"(.+)"$/', $token, $pm)) {
                $requiredPhrases[] = $pm[1]; continue;
            }
            if (preg_match('/^-"(.+)"$/', $token, $pm)) {
                $excludedPhrases[] = $pm[1]; continue;
            }
            if (preg_match('/^"(.+)"$/', $token, $pm)) {
                $requiredPhrases[] = $pm[1]; continue;
            }

            // Bare words — extract operator, then boost, then wildcard
            $op   = '';
            $bare = $token;
            if ($bare[0] === '+') { $op = '+'; $bare = substr($bare, 1); }
            elseif ($bare[0] === '-') { $op = '-'; $bare = substr($bare, 1); }

            // Extract boost (^N), must come after wildcard if both present
            $boost = 1.0;
            if (preg_match('/\^(\d+(?:\.\d+)?)$/', $bare, $bm)) {
                $boost = (float)$bm[1];
                $bare  = substr($bare, 0, -strlen($bm[0]));
            }

            // Extract wildcard (*)
            $isWild = false;
            if (substr($bare, -1) === '*') {
                $isWild = true;
                $bare   = rtrim($bare, '*');
            }

            if ($bare === '') continue;

            // Normalize every term with the SAME rules the index was
            // tokenized with (see the tokenization contract near
            // TOKENIZER_DEFAULT). Before this, terms kept ' . - verbatim, so
            // "don't" searched for the term don't — which the build tokenizer
            // guarantees can never exist in the index (it stores dont). One
            // query word may normalize to several terms ("coca-cola" → coca,
            // cola), all inheriting the operator and boost. Wildcard prefixes
            // normalize WITHOUT splitting — a prefix is one unit ("co-op*" →
            // "coop*"); documented behavior choice.
            if ($isWild) {
                $bare = implode('', $this->normalizeQueryTerm($bare));
                if ($bare === '') continue;
                if      ($op === '+') $req_wild[$bare] = $boost;
                elseif  ($op === '-') $exc_wild[$bare] = $boost;
                else                  $opt_wild[$bare] = $boost;
            } else {
                foreach ($this->normalizeQueryTerm($bare) as $t) {
                    if ($boost !== 1.0) $boosts[$t] = $boost;
                    if      ($op === '+') $required[] = $t;
                    elseif  ($op === '-') $excluded[] = $t;
                    else                  $optional[] = $t;
                }
            }
        }

        // Expand phrases
        // Phrase words go through the same normalization (a quoted
        // "don't stop" must find dont + stop, like the unquoted form).
        // Required phrases are ALSO kept as ordered token groups for
        // positional adjacency verification (multi-token normalization keeps
        // order: "coca-cola era" → [coca, cola, era] must be consecutive).
        // The words still join $required exactly as before — retrieval,
        // scoring, coverage, and the title bonus are unchanged; adjacency is
        // a pure extra FILTER applied only when the index has positions.
        $req_phrases = [];
        foreach ($requiredPhrases as $phrase) {
            $group = [];
            foreach (preg_split('/\s+/', trim($phrase)) as $w) {
                foreach ($this->normalizeQueryTerm($w) as $t) {
                    $group[]    = $t;
                    $required[] = $t;
                }
            }
            if (count($group) >= 2) $req_phrases[] = $group;
        }
        // Asymmetry fix: excluded phrases are per-field AND GROUPS
        // instead of dissolving into the flat OR list. +"phrase" has always
        // decomposed as AND ("all words required"); -"phrase" fell into the
        // flat excluded list, where ANY single word excluded the doc — so
        // +"world war" -"cold war" returned nothing by construction ("war"
        // simultaneously required and excluded). Now a doc is excluded only
        // when it contains ALL the phrase's words IN THE SAME FIELD — strictly
        // closer to true phrase exclusion (any doc containing the phrase
        // contains all its words in that field), without positional data.
        // Residual over-exclusion (all words in one field but not adjacent)
        // is documented. Single-word "phrases" stay plain exclusions.
        // Earlier (dissolved into the flat exclusion list):
        // foreach ($excludedPhrases as $phrase) {
        //     foreach (preg_split('/\s+/', trim($phrase)) as $w) {
        //         $excluded = array_merge($excluded, $this->normalizeQueryTerm($w));
        //     }
        // }
        $exc_phrases = [];
        foreach ($excludedPhrases as $phrase) {
            $group = [];
            foreach (preg_split('/\s+/', trim($phrase)) as $w) {
                $group = array_merge($group, $this->normalizeQueryTerm($w));
            }
            // Keep ORDER and duplicates — positional adjacency needs the
            // token sequence ("new new york" ≠ {new, york}). The word-level
            // AND-in-field check reads the group as a set, so duplicates are
            // harmless there (same isset probe twice).
            // Earlier: $group = array_values(array_unique(array_filter($group)));
            $group = array_values(array_filter($group, fn($t) => $t !== ''));
            if (count($group) >= 2) {
                $exc_phrases[] = $group;
            } elseif (count($group) === 1) {
                $excluded[] = $group[0];
            }
        }

        $required = array_values(array_unique(array_filter($required)));
        $excluded = array_values(array_unique(array_filter($excluded)));
        $optional = array_values(array_unique(array_filter(
            array_diff($optional, $required, $excluded)
        )));

        return compact('required', 'excluded', 'optional', 'boosts', 'req_wild', 'exc_wild', 'opt_wild', 'exc_phrases', 'req_phrases');
    }

    // =========================================================================
    // Fuzzy expansion
    // =========================================================================

    /** Required terms are always exact-matched (no fuzzy expansion). */
    private function expandRequired(array $required): array
    {
        $result = [];
        foreach ($required as $orig) {
            $result[$orig] = [$orig];
        }
        return $result;
    }

    /**
     * Expand terms with fuzzy matching via levenshtein().
     *
     * Uses the unique_terms table (fc, len) composite index for fast candidate
     * lookup; queries are deduplicated by first character, so "solar system"
     * queries 's' once.
     *
     * Returns associative array: [term => boost] where boost reflects edit
     * distance — exact matches get 1.0, fuzzy matches decay^distance (see the
     * boost computation in the body for the tuning history).
     *
     * ── Design history ──
     * - Originally returned a flat string[] with all terms weighted equally.
     *   That let "shart" (dist 1 from "smart") score identically to the
     *   original, so short docs with the fuzzy match outranked real matches.
     * - Earlier boost formula: pow(confidence/100, distance) — rejected, only a
     *   15% discount at conf 85; superseded by decay^distance (default 0.5).
     * - An earlier design ran TWO passes per term: first-char match, then a
     *   second-char pass to catch wrong-first-char typos (feinstein→einstein).
     *   The second pass is disabled for speed (~70ms/term) — see the
     *   commented 'chars' line in the body.
     *
     * $maxPerTerm replaces a hardcoded MAX_FUZZY_PER_TERM at the check
     * below; 0 = unlimited.
     *
     * The optional &$byOrigin out-param records WHICH query term produced
     * each variant: [origTerm => [variant => true]]. The flat return value
     * lost that provenance, and the fuzzy noise filter needed it — without
     * it, the filter compared a term's doc_freq against variants of OTHER
     * query terms (see the noise filter in search() for the full story).
     * Caveat (pre-existing): a candidate is attributed to the FIRST query
     * term that accepts it (the `break` below), so when two query terms are
     * within edit distance of each other, the later one's variants can be
     * credited to the earlier one.
     *
     * $decay — the fuzzy discount base, the 'fuzzy_decay' ranking knob (once
     * the hardcoded literal 0.5).
     */
    private function expandFlat(array $terms, int $confidence, int $maxPerTerm = self::MAX_FUZZY_PER_TERM, ?array &$byOrigin = null, float $decay = 0.5): array
    {
        if (empty($terms)) return [];
        // Exact-only: return all terms with boost 1.0 (no fuzzy expansion)
        if ($confidence >= 100) return array_fill_keys($terms, 1.0);

        // Build per-term info: max edit distance and length range
        $termInfo = [];
        foreach ($terms as $t) {
            $len = mb_strlen($t);
            $maxDist = max(1, (int)floor($len * (100 - $confidence) / 100));
            $termInfo[] = [
                'term'    => $t,
                'maxDist' => $maxDist,
                'minLen'  => max(1, $len - $maxDist),
                'maxLen'  => $len + $maxDist,
                'chars'   => array_filter([$t[0] ?? '']),
                // Two-pass: also check second char to catch wrong-first-char typos
                // (e.g., feinstein→einstein). Commented out for speed (~70ms/term).
                // 'chars' => array_unique(array_filter([$t[0] ?? '', $t[1] ?? ''])),
            ];
        }

        // Collect all (char, minLen, maxLen) queries, deduplicate by char
        $charQueries = []; // char => [overallMin, overallMax, [termInfo indices]]
        foreach ($termInfo as $idx => $ti) {
            foreach ($ti['chars'] as $char) {
                if (!isset($charQueries[$char])) {
                    $charQueries[$char] = ['min' => PHP_INT_MAX, 'max' => 0, 'terms' => []];
                }
                $charQueries[$char]['min'] = min($charQueries[$char]['min'], $ti['minLen']);
                $charQueries[$char]['max'] = max($charQueries[$char]['max'], $ti['maxLen']);
                $charQueries[$char]['terms'][$idx] = true;
            }
        }

        // One query per unique character, check levenshtein on candidates.
        // Earlier $expanded was a flat array, all fuzzy matches weighted equally.
        // Problem: "shart" (dist 1 from "smart") scored same as "smart" itself,
        // causing short "Shart" articles to outrank "Smart" in BM25.
        // Now: $expanded is [term => boost] with distance-based discounting.
        $expanded = [];
        // Per-term match counter: limits fuzzy variants per original query term.
        // Without this, "history" generates 16 variants, "roman" 14, etc. — mostly noise.
        // With 7 query terms × 16 variants = 112 terms → 172K candidate docs → 43s.
        // Capping at MAX_FUZZY_PER_TERM keeps the closest matches (found first due to
        // index ordering) and drops diminishing-value variants.
        $termMatchCount = array_fill(0, count($termInfo), 0);

        $stmt = $this->db->prepare(
            "SELECT term FROM unique_terms WHERE fc = :fc AND len BETWEEN :minLen AND :maxLen"
        );

        foreach ($charQueries as $char => $q) {
            $stmt->bindValue(':fc', $char);
            $stmt->bindValue(':minLen', $q['min'], \PDO::PARAM_INT);
            $stmt->bindValue(':maxLen', $q['max'], \PDO::PARAM_INT);
            $stmt->execute();

            while ($candidate = $stmt->fetch(\PDO::FETCH_COLUMN)) {
                foreach (array_keys($q['terms']) as $idx) {
                    $ti = $termInfo[$idx];
                    // Skip if we've already hit the per-term limit, UNLESS this candidate
                    // is the original term itself (exact match must always be included).
                    // Bug found: an earlier check `if (count >= limit) continue` skipped ALL
                    // candidates after the limit, including the original term "water" when
                    // 10 alphabetically-earlier fuzzy matches filled the quota first.
                    // The limit is config (max_fuzzy_per_term); 0 = unlimited.
                    if ($candidate !== $ti['term'] && $maxPerTerm > 0 && $termMatchCount[$idx] >= $maxPerTerm) continue;
                    // Two-tier levenshtein for speed.
                    // Earlier: pure PHP damerauLevenshtein on every candidate:
                    //   $dist = self::damerauLevenshtein($ti['term'], $candidate);
                    //   Problem: damerauLevenshtein is pure PHP DP — ~10x slower than
                    //   PHP's built-in C-optimized levenshtein(). On 30K+ candidates per
                    //   term, this added ~150ms/term (450ms for 3-term query).
                    //
                    // Now: use C levenshtein() as primary filter, only call PHP
                    // damerauLevenshtein for marginal cases where a transposition could
                    // reduce distance by 1 (levenshtein == maxDist+1).
                    //   - levenshtein() <= maxDist → accept (C speed)
                    //   - levenshtein() == maxDist+1 → might be transposition, check damerau
                    //   - levenshtein() > maxDist+1 → reject (damerau can't save >1 edit)
                    //
                    // This preserves Damerau transposition detection (e.g., "einstien" →
                    // "einstein") while running 10x faster on the common case.
                    $cDist = levenshtein($ti['term'], $candidate);
                    if ($cDist > $ti['maxDist'] + 1) continue; // fast reject via C code
                    $dist = ($cDist <= $ti['maxDist']) ? $cDist : self::damerauLevenshtein($ti['term'], $candidate);
                    if ($dist <= $ti['maxDist']) {
                        // Earliest: $expanded[] = $candidate;  (flat, boost=1.0 for everything)
                        //
                        // Then: boost = pow(confidence/100, distance)
                        // Problem: at 85% confidence, dist 1 = 0.85 boost — only a 15%
                        // discount. BM25 length normalization gave short docs ("Shart")
                        // a 2-3x advantage, easily overwhelming the 15% penalty.
                        //
                        // Now: steeper discount using pow(decay, distance), decay 0.5 by
                        // default: dist 0 = 1.0, dist 1 = 0.5, dist 2 = 0.25. This makes
                        // fuzzy matches clearly subordinate to exact matches. Tried
                        // pow(0.7, dist) — still not enough for BM25; pow(0.5, dist)
                        // works across all algorithms. The base is the 'fuzzy_decay'
                        // ranking knob.
                        $boost = ($dist === 0) ? 1.0 : pow($decay, $dist);
                        // If term matches multiple query terms, keep the best (highest) boost
                        $expanded[$candidate] = max($expanded[$candidate] ?? 0.0, $boost);
                        // record provenance for the noise filter
                        if ($byOrigin !== null) {
                            $byOrigin[$ti['term']][$candidate] = true;
                        }
                        // Only count NON-exact matches toward the per-term limit.
                        // Exact matches (dist=0) are the user's actual term and must always
                        // be included. Bug found: without this, "water" was excluded because
                        // 10 distance-1 matches (alphabetically before "water") filled the
                        // limit before the exact match was reached.
                        if ($dist > 0) {
                            $termMatchCount[$idx]++;
                        }
                        break;
                    }
                }
            }
        }

        // Already associative [term => boost], no dedup needed (the flat-array
        // version returned array_values(array_unique($expanded))).
        return $expanded;
    }

    // =========================================================================
    // Damerau-Levenshtein distance
    // =========================================================================
    // Counts adjacent transpositions as a single edit (cost 1) rather than two
    // (delete + insert). Transpositions are the most common type of typo:
    //   "teh" → "the", "einstien" → "einstein", "recieve" → "receive"
    //
    // Standard levenshtein treats these as distance 2, which means they often
    // exceed maxDist for short-to-medium words at reasonable confidence levels.
    // Damerau-Levenshtein fixes this.
    //
    // This is the Optimal String Alignment variant (not full Damerau) — it
    // doesn't allow a substring to be both transposed and further edited.
    // That's fine for typo correction where we only care about small distances.
    //
    // Performance: ~2x slower than PHP's built-in levenshtein() for same-length
    // strings, but still fast enough since we only call it on pre-filtered
    // candidates from the unique_terms index (typically <1000 per query term).

    private static function damerauLevenshtein(string $s, string $t): int
    {
        $sLen = strlen($s);
        $tLen = strlen($t);

        if ($sLen === 0) return $tLen;
        if ($tLen === 0) return $sLen;

        // Quick reject: if length difference exceeds reasonable edit distance,
        // skip the full DP computation
        if (abs($sLen - $tLen) > max(2, min($sLen, $tLen) / 3)) {
            return abs($sLen - $tLen);
        }

        // Use two-row DP for memory efficiency
        $prev2 = []; // row i-2
        $prev  = range(0, $tLen); // row i-1
        $curr  = [];

        for ($i = 1; $i <= $sLen; $i++) {
            $curr[0] = $i;
            for ($j = 1; $j <= $tLen; $j++) {
                $cost = ($s[$i - 1] === $t[$j - 1]) ? 0 : 1;
                $curr[$j] = min(
                    $prev[$j] + 1,      // deletion
                    $curr[$j - 1] + 1,   // insertion
                    $prev[$j - 1] + $cost // substitution
                );
                // Transposition: swap adjacent characters costs 1 edit
                if ($i > 1 && $j > 1
                    && $s[$i - 1] === $t[$j - 2]
                    && $s[$i - 2] === $t[$j - 1]
                ) {
                    $curr[$j] = min($curr[$j], $prev2[$j - 2] + $cost);
                }
            }
            $prev2 = $prev;
            $prev  = $curr;
            $curr  = [];
        }
        return $prev[$tLen];
    }

    // =========================================================================
    // Wildcard prefix expansion
    // =========================================================================

    // The first approach queried the postings table with SELECT DISTINCT —
    // very slow for broad prefixes like "th*" because it scans millions of
    // rows in the postings table even with an index. 155s for "th*" on 26M
    // postings.
    //
    // Better: query the unique_terms table instead. It has only ~1.2M rows (already
    // deduplicated) and the fc/len index helps with prefix lookups. Then verify
    // the term exists in the requested field via term_stats (which has a PK index).
    // This is much faster for broad prefixes.
    //
    // Tried: just querying unique_terms without field verification — returned
    // terms that don't exist in the field, inflating result sets. The term_stats
    // check is necessary but fast (PK lookup).
    //
    // Trade-off: for very narrow prefixes (e.g., "photosyn*"), the postings approach
    // might be faster since few rows match. But the difference is negligible
    // (<10ms) while the broad prefix case improves by 100x.
    private function expandPrefix(string $field, string $prefix, int $maxExpansions = self::MAX_WILDCARD_EXPANSIONS): array
    {
        if ($prefix === '') return [];

        // Primary-key RANGE SCAN over unique_terms instead of the fc/len
        // index + per-row LIKE. The earlier query narrowed candidates to "every term
        // sharing the first character within the length range" (tens of
        // thousands of rows for common letters) and evaluated LIKE on each —
        // SQLite only rewrites LIKE 'p%' into an index range under
        // case_sensitive_like/NOCASE conditions that don't hold here. The term
        // PK has BINARY collation and both operands are already lowercased, so
        // "starts with prefix" is exactly the byte range [prefix, prefix+1):
        // one B-tree seek, walk only matching terms. Same result set; UTF-8
        // preserves the prefix property so multibyte prefixes work unchanged.
        // Earlier:
        // $fc = mb_substr($prefix, 0, 1);
        // $minLen = mb_strlen($prefix);
        // $stmt = $this->db->prepare(
        //     "SELECT term FROM unique_terms WHERE fc = :fc AND len >= :minLen AND term LIKE :pat"
        // );
        // $stmt->bindValue(':fc', $fc);
        // $stmt->bindValue(':minLen', $minLen, \PDO::PARAM_INT);
        // $stmt->bindValue(':pat', $prefix . '%');
        // Candidates come from a per-prefix memo. The range scan is
        // FIELD-INDEPENDENT (only the termstats verification that follows cares
        // about the field), yet this method runs once per active field per
        // prefix — so when the scan ran inline here, the identical scan executed
        // three times per prefix on the Wikipedia corpus ("th*" = thousands of
        // rows re-read each time).
        $candidates = $this->prefixCandidates($prefix);

        if (empty($candidates)) return [];

        // Verify terms exist in this field via term_stats, and fetch doc_freq
        // for max_expansions ranking. We prefer rarest terms (lowest doc_freq)
        // because they're most discriminative — "thermodynamics" is more useful
        // than "them" for ranking. Common terms also have near-zero IDF anyway.
        //
        // Earlier this just verified existence and returned all matches (no cap).
        // Problem: "th*" returned 7,076 terms → 991K postings → 78s.
        // Now: fetch doc_freq, sort ascending, cap at max_wildcard_expansions.
        // (Per-field tables: termstats_<field> WHERE term IN (...) — no field
        // column needed. The earlier unified schema was
        // term_stats WHERE field = ? AND term IN (...).)
        $termsWithFreq = [];
        $tsTable = "termstats_$field";
        foreach (array_chunk($candidates, self::BATCH_SIZE) as $batch) {
            $ph = implode(',', array_fill(0, count($batch), '?'));
            $vStmt = $this->db->prepare(
                "SELECT term, doc_freq FROM $tsTable WHERE term IN ($ph)"
            );
            $vStmt->execute($batch);
            while ($row = $vStmt->fetch(\PDO::FETCH_ASSOC)) {
                $termsWithFreq[$row['term']] = (int)$row['doc_freq'];
            }
        }

        if (empty($termsWithFreq)) return [];

        // If within limit (or uncapped), return all — no sorting needed
        if ($maxExpansions <= 0 || count($termsWithFreq) <= $maxExpansions) {
            return array_keys($termsWithFreq);
        }

        // Sort by doc_freq ascending (rarest first) and cap.
        // Alphabetical secondary sort makes the cap boundary DETERMINISTIC.
        // Earlier code (asort) left terms tied on doc_freq at the cutoff in fetch
        // order, so which tied terms survived the cap was unspecified.
        // Earlier:
        // asort($termsWithFreq);
        // return array_keys(array_slice($termsWithFreq, 0, self::MAX_WILDCARD_EXPANSIONS, true));
        $terms = array_keys($termsWithFreq);
        usort($terms, fn($x, $y) => ($termsWithFreq[$x] <=> $termsWithFreq[$y]) ?: strcmp($x, $y));
        return array_slice($terms, 0, $maxExpansions);
    }

    /**
     * All indexed terms starting with $prefix, via a primary-key range scan on
     * unique_terms. Memoized per search() call — the scan is field-independent
     * and expandPrefix() is invoked once per active field per prefix
     * (previously the scan re-ran for every field).
     */
    private function prefixCandidates(string $prefix): array
    {
        if (isset($this->prefixCandidateCache[$prefix])) {
            return $this->prefixCandidateCache[$prefix];
        }
        $upper = self::prefixUpperBound($prefix);
        $sql = "SELECT term FROM unique_terms WHERE term >= :p"
             . ($upper !== null ? " AND term < :ub" : "");
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':p', $prefix);
        if ($upper !== null) $stmt->bindValue(':ub', $upper);
        $stmt->execute();
        return $this->prefixCandidateCache[$prefix] = $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Smallest string strictly greater than every string starting with $prefix,
     * under BINARY collation: increment the last byte; on 0xFF, drop it and
     * carry into the previous byte. Returns null when no upper bound exists
     * (prefix is all 0xFF bytes) — caller then scans to the end of the index.
     */
    private static function prefixUpperBound(string $prefix): ?string
    {
        for ($i = strlen($prefix) - 1; $i >= 0; $i--) {
            $byte = ord($prefix[$i]);
            if ($byte < 0xFF) {
                return substr($prefix, 0, $i) . chr($byte + 1);
            }
        }
        return null;
    }

    /**
     * Column names of the documents table, or [] if the table doesn't exist.
     * Cached — introspected once per Searcher instance. Makes the documents
     * table optional and detects whether it carries a title column.
     */
    private function documentsColumns(): array
    {
        if ($this->documentsCols === null) {
            $exists = $this->db->query(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'documents'"
            )->fetchColumn();
            $this->documentsCols = $exists
                ? $this->db->query("PRAGMA table_info(documents)")->fetchAll(\PDO::FETCH_COLUMN, 1)
                : [];
        }
        return $this->documentsCols;
    }

    // =========================================================================
    // Database helpers
    // =========================================================================


    // Per-field WITHOUT ROWID tables.
    // Earlier schema: single `postings` table with field column, queried as:
    //   SELECT doc_id, term, freq FROM postings WHERE field = ? AND term IN (...)
    //
    // Current schema: separate tables per field (postings_title, postings_opening, postings_body).
    //   SELECT doc_id, term, freq FROM postings_title WHERE term IN (...)
    //   No field column needed — the table IS the field.
    //   WITHOUT ROWID + PK(term, doc_id) means data lives directly in the B-tree.
    //   Every query is a covering index scan by default.
    //
    // Benefits: eliminates field column overhead, no rowid lookups, smaller B-trees,
    // each field's data is physically contiguous for better cache locality.
    private function fetchPostings(string $field, array $terms): array
    {
        if (empty($terms)) return [];
        $table = "postings_$field";
        $out = [];
        foreach (array_chunk(array_values($terms), self::BATCH_SIZE) as $batch) {
            $ph   = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $this->db->prepare(
                "SELECT doc_id, term, freq FROM $table WHERE term IN ($ph)"
            );
            $stmt->execute($batch);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $out[$row['doc_id']][$row['term']] = (int)$row['freq'];
            }
        }
        return $out;
    }

    /**
     * Fetch postings for specific terms AND specific doc_ids only.
     *
     * Used in phase 2 of two-phase retrieval. After phase 1 identifies the top
     * candidates using the light fields, this method fetches heavy-field
     * postings ONLY for those surviving doc_ids.
     *
     * Plain fetchPostings('body', $terms) returns ALL docs matching any term
     *   — for "solar" that's ~40K rows from body alone. Most are pruned away later.
     *
     * Here: filter by doc_id IN (...) at the SQL level, so SQLite only returns
     *   rows for the ~500 survivor docs. With PK(term, doc_id) in a WITHOUT ROWID table,
     *   SQLite can seek directly to each (term, doc_id) pair in the B-tree.
     *
     * Query strategy: batch by terms (outer), filter by doc_ids (inner IN clause).
     * Since PK is (term, doc_id), SQLite seeks to each term, then binary-searches
     * within that term's range for matching doc_ids. Very efficient.
     */
    private function fetchPostingsForDocs(string $field, array $terms, array $docIds): array
    {
        if (empty($terms) || empty($docIds)) return [];
        $table = "postings_$field";
        $out = [];
        // Build doc_id placeholder once (reused across term batches)
        $docPh = implode(',', array_fill(0, count($docIds), '?'));
        foreach (array_chunk(array_values($terms), self::BATCH_SIZE) as $termBatch) {
            $termPh = implode(',', array_fill(0, count($termBatch), '?'));
            $stmt = $this->db->prepare(
                "SELECT doc_id, term, freq FROM $table
                 WHERE term IN ($termPh) AND doc_id IN ($docPh)"
            );
            $stmt->execute(array_merge($termBatch, $docIds));
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $out[$row['doc_id']][$row['term']] = (int)$row['freq'];
            }
        }
        return $out;
    }

    /**
     * Position blobs for specific terms AND doc_ids, decoded.
     * Returns [docId => [term => [positions]]]. Same batching/PK-seek shape
     * as fetchPostingsForDocs; only called on pruned candidates for phrase
     * words (see the positional phrase block in search()). A NULL blob
     * decodes to [] and never satisfies adjacency — plain null-safety; the
     * Builder refuses to mix positional and position-less rows in one table.
     */
    private function fetchPositionsForDocs(string $field, array $terms, array $docIds): array
    {
        if (empty($terms) || empty($docIds)) return [];
        $table = "postings_$field";
        $out = [];
        $docPh = implode(',', array_fill(0, count($docIds), '?'));
        foreach (array_chunk(array_values($terms), self::BATCH_SIZE) as $termBatch) {
            $termPh = implode(',', array_fill(0, count($termBatch), '?'));
            $stmt = $this->db->prepare(
                "SELECT doc_id, term, pos FROM $table
                 WHERE term IN ($termPh) AND doc_id IN ($docPh)"
            );
            $stmt->execute(array_merge($termBatch, $docIds));
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $out[$row['doc_id']][$row['term']] = self::decodePositions($row['pos']);
            }
        }
        return $out;
    }

    private function fetchFieldStats(array $fields): array
    {
        if (empty($fields)) return [];
        $ph   = implode(',', array_fill(0, count($fields), '?'));
        $stmt = $this->db->prepare(
            "SELECT field, total_docs, total_length FROM field_stats WHERE field IN ($ph)"
        );
        $stmt->execute(array_values($fields));
        $out = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $out[$row['field']] = [
                'total_docs'   => (int)$row['total_docs'],
                'total_length' => (int)$row['total_length'],
            ];
        }
        return $out;
    }

    /**
     * Co-occurrence vouch for the swap_context_check knob: the fraction of
     * $orig's documents (any active field, sampled up to $cap per field) that
     * also contain at least one of $contextTerms in the SAME field. High ratio
     * = the other query words keep company with this one, so it's established
     * vocabulary in context ("rukh" lives in shah/khan documents), not a typo.
     *
     * Cost: one PK prefix scan per field for $orig + chunked PK point-lookups
     * for the context probe. Only called when a swap is about to fire, and the
     * swap requires the original to be rare relative to its variant, so the
     * doc set is small in practice; $cap bounds the pathological case.
     *
     * Same-field probing is deliberate: it needs no cross-field doc-set merge,
     * and a voucher in the same field is the stronger signal. It can
     * UNDERCOUNT docs whose context word sits only in another field — fine
     * for a guard that asks "is there substantial co-occurrence?", and the
     * knob threshold is calibrated against this measure.
     */
    private function contextVouchRatio(array $fields, string $orig, array $contextTerms, int $cap = 2000): float
    {
        $seen = 0; $vouched = 0;
        $ctx  = array_values(array_unique($contextTerms));
        if (empty($ctx)) return 0.0;
        $ctxPh = implode(',', array_fill(0, count($ctx), '?'));
        foreach ($fields as $field) {
            $stmt = $this->db->prepare(
                "SELECT doc_id FROM postings_$field WHERE term = ? LIMIT $cap"
            );
            $stmt->execute([$orig]);
            $docIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            if (empty($docIds)) continue;
            $seen += count($docIds);
            // Chunk below BATCH_SIZE so ctx placeholders + doc chunk stay
            // under SQLite's parameter limit.
            $chunkSize = max(1, self::BATCH_SIZE - count($ctx));
            foreach (array_chunk($docIds, $chunkSize) as $batch) {
                $docPh = implode(',', array_fill(0, count($batch), '?'));
                $stmt2 = $this->db->prepare(
                    "SELECT COUNT(DISTINCT doc_id) FROM postings_$field
                     WHERE term IN ($ctxPh) AND doc_id IN ($docPh)"
                );
                $stmt2->execute(array_merge($ctx, $batch));
                $vouched += (int)$stmt2->fetchColumn();
            }
        }
        return $seen > 0 ? $vouched / $seen : 0.0;
    }

    // Per-field termstats tables:
    //   SELECT term, doc_freq FROM termstats_title WHERE term IN (...)
    // No field column needed — PK is just (term). (Earlier unified schema:
    //   SELECT term, doc_freq FROM term_stats WHERE field = ? AND term IN (...))
    //
    // Also reads the pre-computed IDF column into $this->termIdfByF[$field][$term].
    // Eliminates log(($N+0.5)/($df+0.5)+1) computation in scoreDoc — IDF depends only
    // on N and df, both fixed for a given index.
    private function fetchTermStats(string $field, array $terms): array
    {
        if (empty($terms)) return [];
        $table = "termstats_$field";
        $out = [];
        foreach (array_chunk(array_values($terms), self::BATCH_SIZE) as $batch) {
            $ph   = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $this->db->prepare(
                "SELECT term, doc_freq, idf FROM $table WHERE term IN ($ph)"
            );
            $stmt->execute($batch);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $out[$row['term']] = (int)$row['doc_freq'];
                // Store pre-computed IDF for scoreDoc to use
                if ($row['idf'] !== null) {
                    $this->termIdfByF[$field][$row['term']] = (float)$row['idf'];
                }
            }
        }
        return $out;
    }

    // Merged doclens table — one query for all fields instead of one per field.
    // Helps most on shared/slow disk where each SQL round-trip has latency.
    //
    // Earlier: one doclens_<field> table each, three separate queries:
    // private function fetchDocLengths(array $docIds, array $fields): array
    // {
    //     if (empty($docIds) || empty($fields)) return [];
    //     $out = [];
    //     foreach ($fields as $field) {
    //         $table = "doclens_$field";
    //         foreach (array_chunk(array_values($docIds), self::BATCH_SIZE) as $batch) {
    //             $ph = implode(',', array_fill(0, count($batch), '?'));
    //             $stmt = $this->db->prepare(
    //                 "SELECT doc_id, length FROM $table WHERE doc_id IN ($ph)"
    //             );
    //             $stmt->execute($batch);
    //             while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
    //                 $out[$row['doc_id']][$field] = (int)$row['length'];
    //             }
    //         }
    //     }
    //     return $out;
    // }
    //
    // Now: single doclens table with columns for all fields. One SQL round-trip.
    private function fetchDocLengths(array $docIds, array $fields): array
    {
        if (empty($docIds) || empty($fields)) return [];
        $out = [];
        // Build the column list from the active fields instead of hardcoding
        // the Wikipedia ones, so custom-field indexes work. Field names come from
        // field_stats / the fields option and match ^[a-z0-9_]+$, so interpolation
        // into the column list is safe.
        // Earlier: "SELECT doc_id, len_title, len_opening, len_body FROM doclens ..."
        $lenCols = implode(', ', array_map(fn($f) => "len_$f", $fields));
        foreach (array_chunk(array_values($docIds), self::BATCH_SIZE) as $batch) {
            $ph = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $this->db->prepare(
                "SELECT doc_id, $lenCols
                 FROM doclens WHERE doc_id IN ($ph)"
            );
            $stmt->execute($batch);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                foreach ($fields as $field) {
                    $out[$row['doc_id']][$field] = (int)($row["len_$field"] ?? 0);
                }
            }
        }
        return $out;
    }

    private function getDefaultFields(): array
    {
        $stmt = $this->db->query("SELECT field FROM field_stats ORDER BY field");
        return array_fill_keys($stmt->fetchAll(\PDO::FETCH_COLUMN), 1.0);
    }

    // $algo param so the return shape matches search()'s success path.
    // An empty result can occur before auto resolves (the AUTO_PENDING
    // sentinel must never leak to callers).
    private function emptyResult(int $page, int $perPage, string $algo = ''): array
    {
        if ($algo === self::AUTO_PENDING) $algo = 'auto';
        return ['hits' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 1, 'algo' => $algo];
    }
}
