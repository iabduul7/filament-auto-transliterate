# Changelog

All notable changes to `filament-auto-transliterate` will be documented in this file.

## v0.1.0 - 2026-06-02

Initial release.

- Inline, as-you-type conversion for Filament inputs via the `->translatable()` macro (with an `->autoTransliterate()` alias for hosts that define their own `translatable` macro).
- Two distinct modes: `transliterate` (Roman Urdu to Urdu script, default) and `translate` (by meaning, opt-in). Transliterate never silently falls through to translation.
- Pluggable provider chain (Google Input Tools, MyMemory, LibreTranslate, Microsoft, Google, local dictionary) configurable per mode.
- Auth-gated, throttled HTTP endpoint.
- Permanent, cross-database translation cache (hash-indexed) with an `install` command to publish + run the migration.
- Header on/off toggle with a persistent enabled indicator, an in-field loading spinner, and prebuilt JS/CSS assets — all wired up by adding the plugin to a panel.
- Supports Filament v4 and v5.
