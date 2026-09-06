# 02 — Self-improvement (learning from corrections)

## Goal

The package should get better the more it is used — without any paid service. The
highest-signal, zero-cost training data we have is **the user fixing an applied
word**: they typed `mkan`, we applied `مکن`, they corrected it to `مکان`. That pair
is ground truth from a native speaker, for exactly the vocabulary this installation
uses. Capture it, store it, and prefer it forever after.

This was already on the README roadmap ("Learn-from-correction"); this doc is the
concrete design.

## How it works, end to end

```
type "mkan" ─space→ apply "مکن" ─user edits to "مکان" ─blur/next-word→
POST /learn {original:"mkan", corrected:"مکان", target_lang:"ur", mode:"transliterate"}
→ cache row (source=user_correction, confidence=0.99)
→ every future "mkan" on this install returns "مکان" — instantly, offline, for free
```

The existing cache is already consulted **before** any provider
(`TranslationService::translate()`), so corrections need no new lookup path — a
correction is just a cache row that outranks provider output.

## Server side

### Storage — reuse `translation_cache`

No new table. A correction is written through the existing
`TranslationCache::cacheTranslation()` with `source = 'user_correction'` and
`confidence = 0.99`. Benefits: the hash-index lookup, the mode/target keying, and
`getStats()`'s by-source breakdown (corrections become visible in stats for free).

One behavioral guard in `cacheTranslation()`: **a `user_correction` row is never
overwritten by a non-correction source.** (Today this can't happen via
`translate()` because caching only runs on a cache miss, but the guard makes the
invariant explicit and protects future call sites. A newer correction may replace an
older correction.)

### New endpoint — `POST /learn`

Added to `routes/web.php` inside the existing auth-gated, throttled group:

- Payload: `{original, corrected, target_lang?, mode?}`.
- Validation (in `TranslationController::learn()`):
  - `original`: required, string, `max:max_text_length`, must **not** match the
    target-script pattern (you can't "correct" something that was never romanized);
  - `corrected`: required, string, same max, **must** match the target-script
    pattern for the resolved language — this is the main defense against garbage
    writes (someone replacing the applied word with unrelated Latin text);
  - `corrected !== original`; `target_lang` in `Languages::codes()`; `mode` in the
    two known modes.
- Config kill switch: `'learn' => ['enabled' => true]`. When disabled the route
  returns 404 and the JS never sends.
- Rate limiting: the group throttle already applies; corrections are rarer than
  translations so no separate limit needed.

Trust model: the endpoint is authenticated (`['web', 'auth']`), so "poisoning" the
correction store requires a logged-in panel user — the same person who can already
type anything into the fields being transliterated. Per-user attribution (and
review/rollback) is a paid-tier feature, see doc 05.

### Service method

`TranslationService::learn(string $original, string $corrected, ?string $targetLang, $mode): array`
— thin, testable wrapper that resolves language/mode and writes the row. The
controller stays a validator + JSON shell.

## Client side (`translation-overlay.js`)

Detecting "the user fixed our word" without annoying false positives is the hard
part. Design principle: **only learn the unambiguous case; discard everything
else.** A missed correction costs nothing (the user can fix the word again next
time); a wrong learned pair actively hurts.

Mechanism — token-diff against a snapshot, no cursor bookkeeping:

1. On `applyInline()`, record per input: `{original, applied, targetLang, mode,
   valueAfterApply}` (the full field value right after the swap). Keep only the most
   recent record per field — corrections overwhelmingly happen immediately, while
   the wrong word is still under the user's eyes.
2. Check for a correction at exactly two trigger points: **(a)** field blur,
   **(b)** just before the next conversion is applied in the same field.
3. The check: tokenize `valueAfterApply` and the current value on whitespace.
   Accept only if the token arrays are the same length (trailing new tokens are
   allowed at trigger (b), since the user has typed the next word) **and exactly one
   position differs**, where the old token `=== applied` and the new token differs,
   is non-empty, and matches the target script. Anything else — reordered words,
   multiple edits, deletions, splits — is discarded.
4. On accept: `POST config.learnEndpoint`, update the in-memory `wordCache` so the
   correction takes effect immediately in this browser session, clear the record.
   Fire-and-forget; a failed learn call is logged (debug) and never surfaces UI.

Also: when a `/translate` response comes back for a word that has a pending
correction record with the same original, drop the record (the field was re-run,
snapshot is stale).

## Beyond corrections — other self-improvement loops

Ordered by value/effort; the first is part of this plan, the rest are candidates:

1. **Corrections-first cache** (this doc) — ships now.
2. **Frequency-weighted client preload**: track hit counts per cache row
   (`hits` int column, incremented on cache hit); expose the top-N rows per
   language via a small endpoint; the JS seeds `wordCache` with them on load. Most
   data-entry vocabularies are a few hundred words — after a week the common path
   never touches the network. (Cheap; needs one migration.)
3. **Alternative-pick learning**: once the UI can show Google's alternative
   candidates (doc 04), a user picking candidate #2 is the same signal as a
   correction — store it identically (`source = 'user_pick'`).
4. **Shared/exportable dictionaries**: `artisan filament-auto-transliterate:export-learned`
   dumps `user_correction` rows to the JSON dictionary format the
   `DictionaryProvider` already reads — so one installation's learning can bootstrap
   another. (Also the seed of the paid "team glossary" feature, doc 05.)

## Files touched

| File | Change |
| ---- | ------ |
| `config/filament-auto-transliterate.php` | `learn.enabled` |
| `routes/web.php` | `POST /learn` |
| `src/Http/Controllers/TranslationController.php` | `learn()` action |
| `src/Services/TranslationService.php` | `learn()` method |
| `src/Models/TranslationCache.php` | never-overwrite-correction guard |
| `src/Macros/TranslatableMacro.php` | `learnEndpoint` in `data-fat-config` |
| `resources/js/translation-overlay.js` | snapshot + token-diff detector, learn POST |
| tests | endpoint validation matrix; correction-wins-over-provider; guard test |

## Test plan (highlights)

- Correction stored → subsequent `translate()` returns it with `source =
  user_correction` and **no** HTTP request recorded.
- Provider result cannot overwrite a correction row; a newer correction can.
- `learn` rejects: corrected text in Latin script; corrected == original; unknown
  target; unauthenticated; `learn.enabled = false` → 404.
