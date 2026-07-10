# 04 — Better transliteration on free services

## Goal

The default install uses only free/unauthenticated services (Google Input Tools for
transliteration; MyMemory/LibreTranslate for translation). Free services rate-limit,
go down, and return exactly one candidate. This plan is every practical lever for
getting **better output and higher availability without paid keys** — most leverage
comes from never asking the free service in the first place.

## Layered strategy

Requests should fall through layers, cheapest and most-trusted first:

```
1. user corrections        (DB cache, source=user_correction)   ← doc 02
2. permanent result cache  (DB cache, provider results)          ← exists
3. local dictionaries      (JSON files, offline)                 ← extend
4. free network provider   (Google Input Tools)                  ← harden
5. nothing                 (leave text unchanged — the guarantee)
```

Layers 1–2 already share one lookup (the cache query). The work is in 3 and 4, plus
client-side discipline so layer 4 is hit as rarely as possible.

## Server-side work

### A transliteration dictionary, separate from the translation dictionary

`DictionaryProvider` currently sits only in the `translate` chain, reading
`dictionary_path`. Reusing that same file for transliterate mode would violate the
core guarantee (a meaning-glossary answering a phonetic query). Instead:

- New `TransliterationDictionaryProvider` (subclass; overrides the config key and
  provider key) reading `transliterate_dictionary_path` — a
  `{ "roman word": "target-script word" }` map, `{target}` placeholder supported.
- Default transliterate chain becomes
  `['transliterate_dictionary', 'google_input_tools']` (a `null` path makes the
  provider report unconfigured and skip — zero cost when unused).
- This is where domain vocabulary lives: product names, city names, honorifics —
  exactly the words generic engines get wrong. Combined with
  `export-learned` (doc 02) the dictionary can be *grown from real usage*.

### Multiple candidates from Google Input Tools

The provider hard-codes `num: 1`. Request `num: 4` (config:
`suggestions_per_word`): same request cost, and the alternatives feed two things:

- `TranslationResult` gains `alternatives: string[]` (single-segment responses
  only), passed through `toArray()` to the JS.
- Near-term UI: none required (applying the first candidate stays the behavior).
  Later: alt-click / long-press on an applied word showing a candidate picker; a
  pick is stored like a correction (`source = user_pick`, doc 02). The plumbing is
  cheap now; the picker can come later.

### Circuit breaker on failing providers

Free endpoints fail in bursts. Today every request walks the full chain and eats a
timeout per dead provider (`api_timeout` = 5s — worst case per word!). Add to
`TranslationService`:

- Track consecutive failures per provider in the app cache
  (`fat_provider_cb_{key}`). After `provider_failure_threshold` (default 3)
  failures, skip the provider for `provider_failure_cooldown` (default 120s).
  Any success resets the counter.
- Log one warning when a breaker opens, not one per skipped request.

Also drop the default `api_timeout` for the transliterate chain to ~3s — an inline
keyboard helper that answers after 5s answers too late anyway.

### Respect free-tier etiquette

- MyMemory grants larger quotas when `mymemory_email` is set — already supported;
  README should say so louder.
- Self-hosted LibreTranslate is the "free but unlimited" translate option — already
  supported via `libretranslate_url`; document it as the recommended path for
  volume translate-mode use.

## Client-side work (`translation-overlay.js`)

The cheapest request is the one never sent:

- **In-flight de-duplication**: a `pending` map keyed by `mode:lang:word`. Rapid
  space-space on the same word (common when typing fast) currently fires two
  requests; the second should await the first's promise.
- **Negative caching (session)**: a word that returned "no conversion" is cached as
  a miss for the session; re-typing it doesn't re-request. Bounded (e.g. 500
  entries, FIFO) so an unbounded Map can't grow all shift.
- **429 backoff**: on a 429 the overlay shows its "pausing" message but keeps
  converting on the next space. Add a client-side cool-down (skip requests for
  ~15s after a 429, message once) so a throttled user doesn't hammer the endpoint.
- **min-length guard**: `min_text_length` is already delivered in the field config
  but unused by the JS — enforce it (1–2 char tokens are usually particles the
  engine mangles anyway; skipping them is a quality *and* quota win).
- (From doc 02) **preload of top learned/frequent words** into `wordCache` — after
  warm-up, the common path is fully offline.

## Explicit non-strategies

- **Char-map fallback stays off by default.** "Better on free services" must not
  mean "emit phonetic garbage when the service is down" — the existing opt-in
  `fallback_transliteration` flag and its warnings stay exactly as they are.
- **No scraping/undocumented-API abuse** beyond the already-used public Input Tools
  endpoint, and no rotating through proxies to dodge rate limits. Being a good
  citizen keeps the free path alive for everyone.

## Files touched

| File | Change |
| ---- | ------ |
| `config/filament-auto-transliterate.php` | `transliterate_dictionary_path`, `suggestions_per_word`, breaker thresholds; transliterate chain default |
| `src/Providers/TransliterationDictionaryProvider.php` | new (small subclass) |
| `src/Providers/DictionaryProvider.php` | extract overridable config-key hook |
| `src/Providers/GoogleInputToolsProvider.php` | `num` from config; alternatives |
| `src/Data/TranslationResult.php` | `alternatives` field |
| `src/Services/TranslationService.php` | circuit breaker |
| `resources/js/translation-overlay.js` | pending map, negative cache, 429 cool-down, min-length |
| tests | breaker opens/closes; dictionary-first chain; alternatives passthrough; mode isolation still holds |
