# Changelog

All notable changes to `filament-auto-transliterate` will be documented in this file.

## v0.1.0 - Unreleased

Initial release.

- Inline, as-you-type conversion for Filament inputs via the `->translatable()` macro.
- Two distinct modes: `transliterate` (Roman Urdu to Urdu script, default) and `translate` (by meaning, opt-in). Transliterate never silently falls through to translation.
- Pluggable provider chain (Google Input Tools, MyMemory, LibreTranslate, Microsoft, Google, local dictionary) configurable per mode.
- Auth-gated, throttled HTTP endpoint.
- Permanent, cross-database translation cache.
- Header on/off toggle and prebuilt JS/CSS assets, wired up by adding the plugin to a panel.
