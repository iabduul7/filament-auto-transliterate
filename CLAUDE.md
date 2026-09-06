# CLAUDE.md — filament-auto-transliterate

Open-source Filament plugin: inline, as-you-type transliteration/translation for form inputs. Type Roman Urdu, get Urdu script on the spacebar, without leaving the field. Extracted from the `malik-and-brothers-goods` app. It is a **keyboard-style input helper**, not a content-translation workflow.

- **Identity:** package `iabduul7/filament-auto-transliterate`, namespace `Iabduul7\FilamentAutoTransliterate`. Published on Packagist (latest v0.2.0). Supports `filament/filament: ^4.0 || ^5.0`, PHP 8.2+.
- **GitHub:** `https://github.com/iabduul7/filament-auto-transliterate`.

## Repo / release workflow
- Develop on **`dev`**; PR `dev` → `main`; tag `vX.Y.Z` on `main` (annotated) and push the tag → Packagist auto-updates via webhook. Then bump the consumer's constraint if needed.
- Two repos live on one machine (this package + the app). Always `cd` into the intended repo explicitly; never batch mutating git in parallel.
- Commit signing fails in this environment — commit with `-c commit.gpgsign=false`.
- Commits: clear, descriptive messages; include Claude as co-author (`Co-Authored-By:` trailer) on AI-assisted commits. No raw model IDs or other tooling identifiers beyond that trailer in commits/PRs.
- Keep `CLAUDE.md`, `README.md`, `CHANGELOG.md`, and `docs/` in sync with code changes — documentation updates are part of the change, not a follow-up.
- `docs/` holds the design plans (multi-language, learning loop, switcher UI, free-tier quality, monetization) with per-doc implementation status. Read the relevant doc before implementing related features; update it when the design changes.

## Build (CRITICAL)
- JS/CSS are authored in `resources/js/translation-overlay.js` + `resources/css/translation-overlay.css`, built with **esbuild** via `npm run build` into `resources/dist/filament-auto-transliterate.{js,css}`.
- The **prebuilt `resources/dist/*` ARE committed** and are what Filament serves (registered via `FilamentAsset` in the service provider). **Always `npm run build` and commit the dist after any JS/CSS source change**, or hosts get stale assets.
- `node_modules` is gitignored — `npm install` on a fresh machine before building.
- A docblock/comment-only JS change produces an identical minified bundle (minification strips comments) — dist may show no diff; that's expected.

## Macro
- Registers `->translatable()` **unconditionally** as the primary macro (Filament has no built-in `translatable` macro, so this is safe), plus `->autoTransliterate()` as an identical alias for hosts that already define their own `translatable`. This is an intentional design choice (the package owns the name) — do not re-add a non-clobber guard.
- The macro tags the input with `data-fat-translatable="true"` + `data-fat-config` (JSON: endpoint, learnEndpoint, targetLang, targetPinned, mode, min/maxLength). A field pinned via `->translatable(target: 'hi')` also carries `data-fat-pinned="<code>"`. The JS overlay keys on these.

## Modes (core identity)
- Two strictly-separated modes: `transliterate` (default — same sounds in target script; on a miss leaves text unchanged) and `translate` (by meaning, opt-in). **Transliterate must NEVER silently fall through to translation** — separate provider chains (`config('...providers.transliterate')` vs `...providers.translate')`). Tests assert this; keep them passing.

## Languages
- `Support\Languages` is the registry over the `languages` config map (10 targets by default: ur ar fa hi mr pa bn ne ru el — trimmed deliberately; more are one config entry away). Script ranges are defined once as hex range pairs and compiled to both a PHP preg pattern and a JS regex source — never hand-write per-engine regexes elsewhere.
- Target resolution: `Languages::resolve()` maps unknown codes to the configured default; controllers additionally 422 on unknown `target_lang`. `target_script_pattern` (default `null`) is a global detection override, kept for back-compat.
- The frontend gets the table via the `window.fatConfig` script injected at `HEAD_END` by the plugin.

## Providers
- One class per backend; implement `Contracts\TranslationProvider`, extend `AbstractProvider`, wire via `provider_map` + per-mode chains in config — never hard-code a provider into the service. Unconfigured providers are skipped.
- Two separate local dictionaries by design: `dictionary_path` (translate mode, meanings) vs `transliterate_dictionary_path` (transliterate mode, romanization) — sharing one file would break the mode-isolation guarantee.
- The service wraps the chain in a circuit breaker (cache key `fat_provider_cb_{key}`; `provider_failure_threshold` / `provider_failure_cooldown`).

## Cache (gotchas)
- Single table (default `translation_cache`, configurable via `table_name`). Lookup index is on `(original_text_hash, target_language, mode)` — there is **no index on the `original_text` TEXT column**. So always read/write via the hash: `getTranslation()` and `cacheTranslation()` use the hash; never `updateOrCreate()` matching on `original_text` (full table scan). The service's write path goes through `cacheTranslation()` for this reason.
- `original_text_hash` is a **MySQL generated column** (`SHA2(original_text,256)`) but a **plain column on SQLite/Postgres**. The model's `saving()` hook populates it on non-MySQL when the text changes OR the hash is null (so adopted/legacy rows get backfilled). Driver-guard any raw DDL in the migration.
- `cacheTranslation()`/`getTranslation()` resolve a null `mode` to the configured default (`config('...mode')`, also the column default) so reads/writes always target one concrete mode and never match across modes.
- Rows with `source = 'user_correction'` (the learn-from-correction loop) are **never overwritten by a non-correction source** — `cacheTranslation()` enforces this; a newer correction may replace an older one.

## JS overlay (gotchas)
- In-field loading spinner anchors to Filament's `.fi-input-wrp` wrapper (the input is a replaced element — can't nest inside it). `position: relative` is scoped to a `.fat-loading-host` marker class so Filament layout isn't touched otherwise; trailing edge via `inset-inline-end` (RTL-safe). Falls back to a below-field box for inputs not in `.fi-input-wrp`.
- Spinners are tracked **per host in a Map with a reference count** — concurrent requests on the same field don't stack or prematurely clear. `observeInputs()` sweeps Map entries whose host was detached by a Livewire morph (else a replaced wrapper keeps `fat-loading-host` stuck).
- In-flight requests **honour toggle-off**: after the response resolves, the handler bails (no field mutation, no message) if `!this.isEnabled`.
- `applyInline()` replaces the last **whole-token** occurrence (whitespace-bounded), not any substring, to avoid corruption when overlapping requests resolve after text shifts.
- Requests are de-duplicated in flight (`pendingRequests` map), misses are negative-cached per session (FIFO-bounded), and a 429 starts a ~15s client cool-down — keep these when touching the fetch path.
- The correction detector only learns the unambiguous case (same token count, exactly one differing token that equals the applied word, replacement in target script) — widen it only with a design-doc update; a wrongly learned pair is worse than a missed one.
- Public surface for hosts: `window.FilamentAutoTransliterate.isEnabled`, `.toggleEnabled(bool)`, `.setTargetLang(code)`, `.getTargetLang()`; localStorage keys `fat_enabled` + `fat_target_lang`; `window.fatConfig` (languages/defaultTarget/learnEnabled) injected by the plugin. There is no JS event/hook API beyond these (a consumer building on top, e.g. a create-option bridge, calls the endpoint itself and reads `isEnabled`).

## Endpoint
- Routes under `config('...route.prefix')` (default `filament-auto-transliterate`), named `filament-auto-transliterate.translate` (+ batch/status/stats/learn). **Auth-gated + throttled by default** — it proxies external APIs, so never make it public. `/learn` additionally 404s when `learn.enabled` is off.
- User input must stay out of logs unless `log_requests` is explicitly on.

## Install command
- `php artisan filament-auto-transliterate:install` (Spatie InstallCommand) publishes config + migration and offers to run migrations. The migration ships as a publishable **stub**, so `php artisan migrate` alone won't create the table until published — the README points users at the install command. Migration publish tag is `filament-auto-transliterate-migrations` (Spatie `shortName()` only strips a `laravel-` prefix, not `filament-`).

## Testing
- Pest + `orchestra/testbench`, in-memory SQLite. Run: `vendor/bin/pest` (or `composer test`). Format: `vendor/bin/pint`.
- `Http::preventStrayRequests()` + `Http::fake()` — never hit real networks in tests.
- The DOM/spinner/switcher JS behaviour is NOT covered by the PHP suite — verify spinner/toggle/dropdown/morph behaviour in a real browser.

## CI / branch protection
- `.github/workflows/run-tests.yml` runs on PRs to (and pushes to) `main`: a `test` matrix (PHP 8.2/8.3/8.4 × Laravel 11/12) plus a `code-style` job (`pint --test`, check name "Pint (code style)").
- **`main` is branch-protected**: all 6 matrix jobs + "Pint (code style)" are REQUIRED status checks, `strict` (branch must be up to date), force-push/deletion disabled. Admins are NOT enforced; no required PR review. So the merge gate is CI-green only.
- The matrix job names ARE the required-check contexts — if you change the matrix (PHP/Laravel versions), update the branch-protection required checks to match, or `main` merges will hang waiting on a check that never reports.
- The separate `fix-php-code-style-issues.yml` only runs on `push` with `**.php` paths and auto-commits Pint fixes — it is NOT a PR gate (that's why a dedicated `pint --test` job exists for protection).
