# CLAUDE.md — filament-auto-transliterate

Open-source Filament plugin: inline, as-you-type transliteration/translation for form inputs. Type Roman Urdu, get Urdu script on the spacebar, without leaving the field. Extracted from the `malik-and-brothers-goods` app.

- **Identity:** package `iabduul7/filament-auto-transliterate`, namespace `Iabduul7\FilamentAutoTransliterate`. Published on Packagist (v0.1.0). Supports `filament/filament: ^4.0 || ^5.0`, PHP 8.2+.
- **GitHub:** `https://github.com/iabduul7/filament-auto-transliterate`.

## Repo / release workflow
- Develop on **`dev`**; PR `dev` → `main`; tag `vX.Y.Z` on `main` (annotated) and push the tag → Packagist auto-updates via webhook. Then bump the consumer's constraint if needed.
- Two repos live on one machine (this package + the app). Always `cd` into the intended repo explicitly; never batch mutating git in parallel.
- Commit signing fails in this environment — commit with `-c commit.gpgsign=false`. No model/tooling identifiers in commits/PRs.

## Build (CRITICAL)
- JS/CSS are authored in `resources/js/translation-overlay.js` + `resources/css/translation-overlay.css`, built with **esbuild** via `npm run build` into `resources/dist/filament-auto-transliterate.{js,css}`.
- The **prebuilt `resources/dist/*` ARE committed** and are what Filament serves (registered via `FilamentAsset` in the service provider). **Always `npm run build` and commit the dist after any JS/CSS source change**, or hosts get stale assets.
- `node_modules` is gitignored — `npm install` on a fresh machine before building.
- A docblock/comment-only JS change produces an identical minified bundle (minification strips comments) — dist may show no diff; that's expected.

## Macro
- Registers `->translatable()` **unconditionally** as the primary macro (Filament has no built-in `translatable` macro, so this is safe), plus `->autoTransliterate()` as an identical alias for hosts that already define their own `translatable`. This is an intentional design choice (the package owns the name) — do not re-add a non-clobber guard.
- The macro tags the input with `data-fat-translatable="true"` + `data-fat-config` (JSON: endpoint, targetLang, mode, min/maxLength). The JS overlay keys on these.

## Modes (core identity)
- Two strictly-separated modes: `transliterate` (default — same sounds in target script; on a miss leaves text unchanged) and `translate` (by meaning, opt-in). **Transliterate must NEVER silently fall through to translation** — separate provider chains (`config('...providers.transliterate')` vs `...providers.translate')`).

## Cache (gotchas)
- Single table (default `translation_cache`, configurable via `table_name`). Lookup index is on `(original_text_hash, target_language, mode)` — there is **no index on the `original_text` TEXT column**. So always read/write via the hash: `getTranslation()` and `cacheTranslation()` use the hash; never `updateOrCreate()` matching on `original_text` (full table scan). The service's write path goes through `cacheTranslation()` for this reason.
- `original_text_hash` is a **MySQL generated column** (`SHA2(original_text,256)`) but a **plain column on SQLite/Postgres**. The model's `saving()` hook populates it on non-MySQL when the text changes OR the hash is null (so adopted/legacy rows get backfilled). Driver-guard any raw DDL in the migration.
- `cacheTranslation()`/`getTranslation()` resolve a null `mode` to the configured default (`config('...mode')`, also the column default) so reads/writes always target one concrete mode and never match across modes.

## JS overlay (gotchas)
- In-field loading spinner anchors to Filament's `.fi-input-wrp` wrapper (the input is a replaced element — can't nest inside it). `position: relative` is scoped to a `.fat-loading-host` marker class so Filament layout isn't touched otherwise; trailing edge via `inset-inline-end` (RTL-safe). Falls back to a below-field box for inputs not in `.fi-input-wrp`.
- Spinners are tracked **per host in a Map with a reference count** — concurrent requests on the same field don't stack or prematurely clear. `observeInputs()` sweeps Map entries whose host was detached by a Livewire morph (else a replaced wrapper keeps `fat-loading-host` stuck).
- In-flight requests **honour toggle-off**: after the response resolves, the handler bails (no field mutation, no message) if `!this.isEnabled`.
- `applyInline()` replaces the last **whole-token** occurrence (whitespace-bounded), not any substring, to avoid corruption when overlapping requests resolve after text shifts.
- Public surface for hosts: `window.FilamentAutoTransliterate.isEnabled` and `.toggleEnabled(bool)`; localStorage key `fat_enabled`. There is no JS event/hook API beyond these (a consumer building on top, e.g. a create-option bridge, calls the endpoint itself and reads `isEnabled`).

## Endpoint
- Routes under `config('...route.prefix')` (default `filament-auto-transliterate`), named `filament-auto-transliterate.translate` (+ batch/status/stats). **Auth-gated + throttled by default** — it proxies external APIs, so never make it public.

## Install command
- `php artisan filament-auto-transliterate:install` (Spatie InstallCommand) publishes config + migration and offers to run migrations. The migration ships as a publishable **stub**, so `php artisan migrate` alone won't create the table until published — the README points users at the install command. Migration publish tag is `filament-auto-transliterate-migrations` (Spatie `shortName()` only strips a `laravel-` prefix, not `filament-`).

## Testing
- Pest + `orchestra/testbench`, in-memory SQLite. Run: `vendor/bin/pest`. Format: `vendor/bin/pint`.
- The DOM/spinner JS behaviour is NOT covered by the PHP suite — verify spinner/toggle/morph behaviour in a real browser.
