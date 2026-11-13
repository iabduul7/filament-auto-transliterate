# Changelog

All notable changes to `filament-auto-transliterate` will be documented in this file.

## v0.4.0 - 2026-11-14

### Added

- **Cache lookup & hit hooks on `TranslationService`:**
  - `TranslationService::scopeCacheLookupUsing(?Closure $callback)` allows packages or host apps to add query constraints (e.g., approval or tenant scoping), return a resolved `TranslationCache` model, or return `false` to bypass the cache.
  - `TranslationService::onCacheHit(?Closure $callback)` fires whenever `translate()` resolves a cache hit (`function (TranslationCache $cached, string $text, string $targetLang, TranslationMode $mode): void`).
- **Runtime provider registration (`TranslationService::extend()`):** register custom providers by class name, closure factory, or instance and optionally attach them `'before'` or `'after'` any mode's provider chain without mutating `config/filament-auto-transliterate.php`.
- **`protected_sources` configuration:** `config('filament-auto-transliterate.protected_sources')` (defaults to `['user_correction']`) defines cache row sources that `TranslationCache::prune()` and non-protected provider cache writes must never delete or overwrite.
- **Cache write context hook (`TranslationCache::resolveWriteContextUsing()`):** register a callback `function (string $originalText, string $targetLang, string $mode, string $source): array` returning `where` constraints (e.g. a tenant column, applied to `cacheTranslation()` matching and inserts, `getTranslation()` and `stampCorrectionUser()`) and extra write `attributes` (e.g. `is_approved`). `TranslationService::flushHooks()` clears it.

## v0.3.4 - 2026-11-07

### Security

- **CI supply-chain hardening:** pinned all GitHub Actions in `.github/workflows/run-tests.yml` and `.github/workflows/fix-php-code-style-issues.yml` to immutable 40-character commit SHAs and configured `cooldown` windows across `github-actions`, `composer`, and `npm` in `.github/dependabot.yml`.

## v0.3.3 - 2026-11-07

### Fixed

- **Pinned-field badge padding & language dropdown height:** added right-side input padding inside `.fi-input-wrp.fat-badge-host` so RTL start-aligned text (`dir="auto"`) never overlaps the `.fat-pinned-badge` chip, and raised `.fat-lang-dropdown` `max-height` to `22rem` so all 10 default languages are visible without scrolling.
- **Documentation media:** added a 10-second multi-script demo GIF and 2560×1440 dark-mode screenshot in `docs/images/` (`export-ignore`d from Composer tarballs) and embedded the demo GIF in `README.md`.

## v0.3.2 - 2026-11-07

### Fixed

- **Header toggle and language switcher styling on Filament v4/v5:** `resources/views/hooks/toggle.blade.php` previously relied on unprefixed Tailwind utility classes (`h-6 w-6`, `h-4 w-4`, `sr-only`, `absolute end-0`, etc.) that Filament v4/v5's precompiled panel stylesheet does not ship unless the host compiles a custom Tailwind theme scanning `vendor/`. All header toggle, status dot, language chip, and dropdown styles are now self-contained `.fat-*` classes in `resources/css/translation-overlay.css` (and prebuilt into `resources/dist/filament-auto-transliterate.css`).

## v0.3.1 - 2026-11-06

Multi-script-first positioning with Arabic (`ar`) as the default target language.

### Changed

- **Default target language (`ar`):** `config('filament-auto-transliterate.target_language')` and internal fallbacks now default to `'ar'` (Arabic / العربية) instead of `'ur'`, and `'ar'` is listed first in the `languages` registry (`ar`, `ur`, `fa`, `hi`, `mr`, `ne`, `bn`, `pa`, `ru`, `el`). Set `FILAMENT_AUTO_TRANSLITERATE_TARGET=ur` in `.env` (or `'target_language' => 'ur'` in `config/filament-auto-transliterate.php`) to keep Urdu as the default.
- **`char_fallback_map`:** now ships keyed for both `'ar'` (Arabic letters) and `'ur'` (Urdu letters).
- **Package metadata & docs:** updated `composer.json` description/keywords and `README.md` to highlight multi-script support (Arabic, Urdu, Hindi, Persian, and 6+ other scripts).

## v0.3.0 - 2026-11-05

Production hardening: provider error handling, endpoint security, an atomic cache, a much sturdier JS overlay, Filament 3.2+ back-compatibility, and CI/hygiene. **Contains breaking changes, see below and "Upgrading to 0.3" in the README.**

### Added

- **Filament 3.2+ back-compatibility:** `composer.json` now allows `filament/filament: ^3.2 || ^4.0 || ^5.0`, and `TranslatableMacro` registers on whichever base component `Field` extends (`Filament\Schemas\Components\Component` on v4/v5, `Filament\Forms\Components\Component` on v3).

- **Miss vs error:** provider results are now either a *miss* (worked, no answer) or an *error* (`TranslationResult::miss()` / `::failure()`, `isMiss()` / `isError()`). Only errors count towards the circuit breaker; misses log at debug.
- **Plugin API:** static `FilamentAutoTransliteratePlugin::authorizeUsing(?Closure)` (Request -> bool, 403 on falsy), `->toggleRenderHook(string)`, `->scriptNonce(Closure|string|null)` (defaults to `Vite::cspNonce()`).
- **Routes/config:** `route.guard` (builds `auth:<guard>`), `route.throttle` (default `60,1`) via the named rate limiters `filament-auto-transliterate` and `filament-auto-transliterate-learn` (per user, falling back to IP), `allowed_modes`, `ops_endpoints.enabled` / `ops_endpoints.probe_providers`, `learn.max_original_length` (200), `learn.throttle` (`20,1`), `learn.min_script_ratio` (1.0), `microsoft_region` (`MICROSOFT_TRANSLATOR_REGION`), `cache_ttl_days`.
- **Cache:** `user_id` column (the correcting user is stored on `user_correction` rows), `TranslationCache::prune(days)` and the `filament-auto-transliterate:prune {--days=}` command. New upgrade migration stub `add_user_id_and_unique_index_to_translation_cache_table`.
- **JS events** on `window`: `fat:ready`, `fat:applied` `{input, original, applied, targetLang, mode}`, `fat:error` `{input, status}`, `fat:toggled` `{enabled}`, `fat:language-changed` `{code}`; plus `refresh()` on `window.FilamentAutoTransliterate` and a client request timeout (ms) attribute, `data-fat-timeout`.
- **JS behaviour:** conversion on Space, textarea Enter, Tab and blur (paste stays untouched); input-event fallback for mobile keyboards/IMEs; punctuation-aware tokens; emails, URLs, digits and already-target-script text are skipped.
- **Accessibility/UI:** keyboard-accessible header switcher (`aria-pressed`, listbox semantics), `role="status"` overlay, higher-contrast colours, `dir="auto"` on converted fields, dark mode via `.dark`.
- **Project hygiene:** `SECURITY.md`, `CONTRIBUTING.md`, issue/PR templates, Dependabot, `phpstan.neon.dist` (level 5), `pint.json`, `.gitattributes` (LF + `export-ignore` for dev files), committed `package-lock.json`, composer scripts `analyse` and `lint`.
- **CI:** matrix now PHP 8.2-8.4 x Laravel 11/12 x Filament 3/4/5 (18 jobs), plus "Pint (code style)" (also `composer validate --strict`), "PHPStan (static analysis)" and "Dist (assets up to date)"; runs on PRs to `dev` and `main`.

### Changed

- All HTTP providers: 3s connect timeout; any non-2xx (including 429) is an error; a malformed payload is an error; an empty or echoed translation is a miss; MyMemory's "MYMEMORY WARNING" quota text is a miss. The Google Translate key is sent as the `?key=` query parameter.
- Dictionary provider: per-target `{target}` files, a missing file is a miss, lowercasing is multibyte-safe.
- Providers are bound as container singletons.
- Provider exceptions are reported to the host handler as a message-free `SanitizedException` (class + file:line, no `previous`) unless `log_requests` is on, so typed text never reaches logs or Sentry.
- A "no result" caused by provider errors or open breakers now carries `transient: true` in the response; the overlay skips negative caching and applies the short cooldown. The cache insert runs in a savepoint inside caller transactions, the upgrade migration backfills NULL hashes on non-MySQL, and the overlay re-attaches after `wire:navigate`.
- Fallback transliteration (opt-in) is transliterate-mode only, is never cached, and `char_fallback_map` may be flat (applies to the default `target_language` only) or keyed by language code.
- `min_text_length` is enforced server-side (no-op "Text too short").
- Controller validates before resolving the language: `target_lang` must be a registered code and `mode` must be in `allowed_modes` (422 otherwise); batch top-level `success` is true when any item succeeded; unexpected errors go to `report()`.
- User text is no longer logged on exceptions unless `log_requests` is on.
- `->translatable()` throws a `LogicException` on non-input components and emits relative endpoint URLs (same-origin on tenancy/subdomain panels). `window.fatConfig` JSON is HEX-escaped.
- Cache lookup index is now UNIQUE and writes are an atomic conditional upsert, so a provider result can never overwrite a correction even under concurrency.
- JS: native undo is preserved (`execCommand`), request timeout via `AbortController` (`apiTimeout`, default 8000ms), status handling (401/403/419 "Session expired" + 30s cool-down, 5xx/network 5s, 429 15s, 422 negative-cached), bounded word cache, CSRF from the meta tag or `XSRF-TOKEN` cookie, singleton guard for `wire:navigate`, and Livewire `morph.added` hook with a debounced rescan. The pinned-field badge is now rendered by JS (`.fat-pinned-badge`).
- `fix-php-code-style-issues` workflow no longer runs on `main`.

### Fixed
- Per-field config was truncated to `{\` in the browser on Filament 5: Laravel's attribute bag rewrites `"` as `\"` instead of HTML-escaping, so the JSON `data-fat-config` attribute never parsed. The macro now emits plain scalar attributes.
- Provider "no result" outcomes no longer trip the circuit breaker.
- Concurrent cache writes no longer race (unique index + atomic upsert); the migration upgrade de-duplicates existing rows.
- Stale spinners left behind by timed-out or aborted requests; toggled-off in-flight requests no longer mutate fields.
- Composition (IME) input and mobile keyboards triggering or missing conversions.
- A flat `char_fallback_map` could previously leak Urdu letters into other target languages.
- Omitting `mode` could sidestep `allowed_modes`.

### Breaking

- **`data-fat-config` is replaced by scalar attributes:** `data-fat-endpoint`, `data-fat-learn-endpoint`, `data-fat-target`, `data-fat-mode`, `data-fat-min-length`, `data-fat-max-length`, `data-fat-timeout` (plus the existing `data-fat-pinned`). Manually tagged inputs that still carry a `data-fat-config` JSON attribute (and no `data-fat-endpoint`) keep working via a legacy fallback.
- **`/provider-status` response shape:** each provider is now `{configured, circuit_open}` (plus `ok` / `error: "unavailable"` when probing) with a top-level `probed`. The old shape is gone.
- **`/stats` and `/provider-status` are disabled by default** (404); enable with `ops_endpoints.enabled`.
- **`route.middleware` now defaults to `null`** (the stack is built from `route.guard`). A published config with an explicit array is still honoured verbatim, but `guard` is ignored in that case; throttling now comes from `route.throttle`.
- **Migration required:** existing installs must publish and run the new upgrade migration (`user_id` + UNIQUE lookup index) before upgrading in production.
- New `allowed_modes` rejects (422) any mode not listed; the default allows both.

## v0.2.0 - 2026-09-06

Multi-language, self-improvement, and free-tier hardening. Design docs in `docs/`.

### Added

- **Language registry** (`Support\Languages`): 10 target languages out of the box (Urdu, Arabic, Persian, Hindi, Marathi, Punjabi, Bengali, Nepali, Russian, Greek), each with native name, RTL flag, and Unicode script ranges compiled into both PHP and JS detection patterns. Fully overridable via the new `languages` config key.
- **Header language switcher**: the on/off toggle gains a chip showing the active language's native name, with a dropdown to switch (persisted per browser). Hide via `->languageSwitcher(false)`; single-language installs render a static label.
- **Per-field target pinning**: `->translatable(target: 'hi')` pins a field to a language regardless of the header selection, with a badge on the field.
- **Learn-from-correction**: when a user fixes an applied word, the correction is reported to a new auth-gated `POST /learn` endpoint and stored as a high-confidence `user_correction` cache row that outranks provider output and is never overwritten by providers. Kill switch: `learn.enabled`.
- **Transliteration dictionary**: a separate local JSON dictionary for transliterate mode (`transliterate_dictionary_path`), checked before any network provider.
- **Provider circuit breaker**: after `provider_failure_threshold` consecutive failures a provider is skipped for `provider_failure_cooldown` seconds.
- **Alternatives**: Google Input Tools now returns up to `suggestions_per_word` candidates; alternatives are included in the response payload.
- Client-side hardening: in-flight request de-duplication, session negative caching, 429 cool-down, `min_text_length` enforcement, first-focus hint.

### Changed

- `target_script_pattern` now defaults to `null` (per-language script detection); setting it still acts as a global override.
- Default transliterate provider chain is now `['transliterate_dictionary', 'google_input_tools']`.
- `target_lang` is validated against the configured language list on all endpoints.

## v0.1.0 - 2026-06-02

Initial release.

- Inline, as-you-type conversion for Filament inputs via the `->translatable()` macro (with an `->autoTransliterate()` alias for hosts that define their own `translatable` macro).
- Two distinct modes: `transliterate` (Roman Urdu to Urdu script, default) and `translate` (by meaning, opt-in). Transliterate never silently falls through to translation.
- Pluggable provider chain (Google Input Tools, MyMemory, LibreTranslate, Microsoft, Google, local dictionary) configurable per mode.
- Auth-gated, throttled HTTP endpoint.
- Permanent, cross-database translation cache (hash-indexed) with an `install` command to publish + run the migration.
- Header on/off toggle with a persistent enabled indicator, an in-field loading spinner, and prebuilt JS/CSS assets — all wired up by adding the plugin to a panel.
- Supports Filament v4 and v5.
