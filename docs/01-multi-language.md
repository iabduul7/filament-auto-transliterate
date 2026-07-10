# 01 — Multi-language transliteration

## Goal

Today the package is architecturally language-agnostic but practically Urdu-only:
`target_language` defaults to `ur`, script detection is a single global regex
(`target_script_pattern`, hard-coded to the Arabic block in both PHP config and JS),
and there is no way to say "this field is Hindi, that one is Urdu". This plan makes
"transliterate from one language to another" a first-class feature.

"One language to another" here means **Roman (Latin) keyboard input → any supported
target script**, per request. The source stays Latin — that's what Google Input
Tools (the transliteration engine) supports and what the product is: a keyboard
helper. Script-to-script conversion (e.g. Urdu→Hindi) is explicitly out of scope for
this iteration; see "Non-goals".

## The language registry

New class `src/Support/Languages.php`, backed by a new `languages` config key. One
authoritative table per target language:

```php
'languages' => [
    'ur' => [
        'label' => 'Urdu',
        'native' => 'اردو',
        'rtl' => true,
        // Unicode block ranges (hex, inclusive) that count as "already in the
        // target script". Multiple ranges per language.
        'script_ranges' => [['0600', '06FF'], ['0750', '077F']],
    ],
    'hi' => [
        'label' => 'Hindi',
        'native' => 'हिन्दी',
        'rtl' => false,
        'script_ranges' => [['0900', '097F']],
    ],
    // ...
],
```

Ranges are stored as hex strings (not regexes) so **one** definition can be compiled
into both a PHP pattern (`/[\x{0600}-\x{06FF}]/u`) and a JS pattern
(`[؀-ۿ]`) — no dual maintenance, no cross-engine regex syntax problems.

`Languages` API:

- `all(): array` — the configured map.
- `codes(): list<string>` — for validation rules.
- `resolve(?string $code): string` — valid code or the configured default. The
  service uses this so an unknown/garbage `target_lang` can never reach a provider.
- `phpScriptPattern(string $code): ?string` — compiled preg pattern.
- `jsPattern(string $code): ?string` — compiled JS regex source (actual characters,
  e.g. `[؀-ۿ]`, built via `mb_chr`), safe to `json_encode`.
- `isRtl(string $code): bool`.
- `forJs(): array` — `{code: {label, native, rtl, pattern}}` payload for the
  frontend (consumed by the header switcher, see doc 03).

### Shipped language set (v1)

Everything below is supported by Google Input Tools' transliteration itc codes
(`{lang}-t-i0-und`):

| Code | Language | Native | Script block(s) | RTL |
| ---- | -------- | ------ | --------------- | --- |
| ur | Urdu | اردو | 0600–06FF, 0750–077F | yes |
| ar | Arabic | العربية | 0600–06FF, 0750–077F | yes |
| fa | Persian | فارسی | 0600–06FF, 0750–077F | yes |
| hi | Hindi | हिन्दी | 0900–097F (Devanagari) | no |
| mr | Marathi | मराठी | 0900–097F | no |
| ne | Nepali | नेपाली | 0900–097F | no |
| bn | Bengali | বাংলা | 0980–09FF | no |
| pa | Punjabi | ਪੰਜਾਬੀ | 0A00–0A7F (Gurmukhi) | no |
| gu | Gujarati | ગુજરાતી | 0A80–0AFF | no |
| ta | Tamil | தமிழ் | 0B80–0BFF | no |
| te | Telugu | తెలుగు | 0C00–0C7F | no |
| kn | Kannada | ಕನ್ನಡ | 0C80–0CFF | no |
| ml | Malayalam | മലയാളം | 0D00–0D7F | no |
| si | Sinhala | සිංහල | 0D80–0DFF | no |
| ru | Russian | Русский | 0400–04FF (Cyrillic) | no |
| el | Greek | Ελληνικά | 0370–03FF | no |
| am | Amharic | አማርኛ | 1200–137F (Ethiopic) | no |
| he | Hebrew | עברית | 0590–05FF | yes |

Implementation note: verify each itc code against the live Input Tools endpoint
before shipping — a couple use legacy codes (Hebrew has historically been `iw` in
Google APIs). If any differ, add an optional `itc` override field per language entry
that `GoogleInputToolsProvider` prefers over `"{code}-t-i0-und"`.

Hosts can trim, extend, or re-label this map freely — it's plain config, and
`provider_map` already lets them register custom providers for languages Google
doesn't cover.

## Per-language script detection

`TranslationService::isAlreadyTargetScript()` currently uses the single global
`target_script_pattern` (defaults to Arabic block) — which means with target `hi`,
Devanagari input would **not** be detected and would be sent to the API needlessly,
while Urdu input in a Hindi field would be wrongly skipped. Change:

1. Signature becomes `isAlreadyTargetScript(string $text, string $targetLang)`.
2. If the host has explicitly set `target_script_pattern` (now defaulting to
   `null`), it wins — preserved as a global override for backwards compat.
3. Otherwise use `Languages::phpScriptPattern($targetLang)`.

Same change client-side: `translation-overlay.js` hard-codes `/[؀-ۿ]/`; it must
instead look up the pattern for the *effective* target language from the injected
`window.fatConfig.languages` payload (doc 03 covers injection).

## Per-field target pinning

The macro (`src/Macros/TranslatableMacro.php`) gains a `target` parameter:

```php
TextInput::make('name_ur')->translatable();                    // follows the global switcher
TextInput::make('name_hi')->translatable(target: 'hi');        // pinned to Hindi
TextInput::make('summary')->translatable(mode: 'translate', target: 'ar');
```

`data-fat-config` gains `targetPinned: bool`. Resolution order in JS:

1. field pin (`targetPinned === true` → use the field's `targetLang`), else
2. the user's header selection (`localStorage.fat_target_lang`), else
3. the server default (`config('...target_language')`).

A pinned field ignores the header switcher by design — a form with one Urdu column
and one Hindi column must keep both correct regardless of the global toggle.

## API / validation changes

- `TranslationController::translate()` / `batchTranslate()`: validate `target_lang`
  with `Rule::in(Languages::codes())` (still nullable). A 422 on an unknown code
  surfaces config mistakes instead of silently transliterating to the default.
- `TranslationService::translate()`: `$targetLang = Languages::resolve($targetLang)`
  as defense-in-depth (the service is a public API for host apps too).
- `GoogleInputToolsProvider` already builds `itc` from `$targetLang`, so it needs no
  change beyond the optional `itc` override noted above.
- Cache rows already key on `target_language` — multi-language caching works as-is.

## Non-goals (this iteration)

- **Script-to-script** conversion (Urdu→Hindi). Different problem, different
  engines; would break the "leaves your text unchanged on a miss" guarantee.
- **Non-Latin source input.** `source_language` stays `en` conceptually; it is only
  used by the *translate* mode providers.
- **Auto-detecting** what language the user is typing. Explicit selection is
  predictable; detection heuristics on 3–8 character romanized words are not.

## Files touched

| File | Change |
| ---- | ------ |
| `config/filament-auto-transliterate.php` | add `languages`; `target_script_pattern` default → `null` |
| `src/Support/Languages.php` | new |
| `src/Services/TranslationService.php` | resolve target, per-language script check |
| `src/Http/Controllers/TranslationController.php` | validate `target_lang` |
| `src/Macros/TranslatableMacro.php` | `target:` param, `targetPinned` in config payload |
| `src/Providers/GoogleInputToolsProvider.php` | optional `itc` override |
| `resources/js/translation-overlay.js` | per-language detection, target resolution order |
| tests | registry unit tests; Devanagari-skip; pin attribute; unknown-target 422 |
