# Changelog

All notable changes to `filament-auto-transliterate` will be documented in this file.

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
