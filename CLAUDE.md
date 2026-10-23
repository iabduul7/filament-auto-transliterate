# CLAUDE.md — filament-auto-transliterate

Open-source Filament plugin: inline, as-you-type transliteration/translation for form inputs. Type Roman Urdu, get Urdu script on the spacebar, without leaving the field. Extracted from the `malik-and-brothers-goods` app. It is a **keyboard-style input helper**, not a content-translation workflow.

- **Identity:** package `iabduul7/filament-auto-transliterate`, namespace `Iabduul7\FilamentAutoTransliterate`. Published on Packagist (latest tagged: v0.2.0; v0.3.0 is the current `dev` release). Supports `filament/filament: ^4.0 || ^5.0`, PHP 8.2+, Laravel 11/12 (CI-tested).
- **GitHub:** `https://github.com/iabduul7/filament-auto-transliterate`.

## Repo / release workflow
- Develop on **`dev`**; PR `dev` → `main`; tag `vX.Y.Z` on `main` (annotated) and push the tag → Packagist auto-updates via webhook. Then bump the consumer's constraint if needed.
- Two repos live on one machine (this package + the app). Always `cd` into the intended repo explicitly; never batch mutating git in parallel.
- Commit signing fails in this environment — commit with `-c commit.gpgsign=false`.
- Commits: clear, descriptive messages; include Claude as co-author (`Co-Authored-By:` trailer) on AI-assisted commits. No raw model IDs or other tooling identifiers beyond that trailer in commits/PRs.
- Keep `CLAUDE.md`, `README.md`, `CHANGELOG.md`, and `docs/` in sync with code changes — documentation updates are part of the change, not a follow-up. CHANGELOG style: `## vX.Y.Z - YYYY-MM-DD`, Added/Changed/Fixed/Breaking, no compare links.
- `docs/` holds the design plans (multi-language, learning loop, switcher UI, free-tier quality, monetization) with per-doc implementation status. Read the relevant doc before implementing related features; update it when the design changes.
- `.gitattributes` forces LF and `export-ignore`s dev files (CLAUDE.md, docs, tests, .github, etc.) from release tarballs.

## Build (CRITICAL)
- JS/CSS are authored in `resources/js/translation-overlay.js` + `resources/css/translation-overlay.css`, built with **esbuild** via `npm run build` into `resources/dist/filament-auto-transliterate.{js,css}`.
- The **prebuilt `resources/dist/*` ARE committed** and are what Filament serves (registered via `FilamentAsset` in the service provider). **Always `npm run build` and commit the dist after any JS/CSS source change**, or hosts get stale assets. CI's "Dist (assets up to date)" job does `npm ci` + build + `git diff` and fails on a stale dist.
- `node_modules` is gitignored — `npm install` on a fresh machine before building. **`package-lock.json` must stay committed** (CI uses `npm ci`).
- A docblock/comment-only JS change produces an identical minified bundle (minification strips comments) — dist may show no diff; that's expected.

## Macro
- Registers `->translatable()` **unconditionally** as the primary macro (Filament has no built-in `translatable` macro, so this is safe), plus `->autoTransliterate()` as an identical alias for hosts that already define their own `translatable`. This is an intentional design choice (the package owns the name) — do not re-add a non-clobber guard.
- Throws `LogicException` on components without `extraInputAttributes()` (non-inputs). Emits **relative** endpoint URLs (`route(..., absolute: false)`) so tenancy/subdomain panels stay same-origin.
- The macro tags the input with plain **scalar** attributes: `data-fat-translatable="true"`, `data-fat-endpoint`, `data-fat-learn-endpoint`, `data-fat-target`, `data-fat-mode`, `data-fat-min-length`, `data-fat-max-length`, `data-fat-timeout` (ms = (`api_timeout`+3)×1000). A field pinned via `->translatable(target: 'hi')` also carries `data-fat-pinned="<code>"`. The JS overlay keys on these (`getConfig()` builds its config object from the dataset). **Never emit a JSON attribute** (the old `data-fat-config`): `ComponentAttributeBag::__toString()` doesn't HTML-escape, it turns `"` into `\"`, so the browser saw `{\` and the parse failed. JS still falls back to a legacy `data-fat-config` JSON only when `data-fat-endpoint` is absent (manual tagging).

## Modes (core identity)
- Two strictly-separated modes: `transliterate` (default — same sounds in target script; on a miss leaves text unchanged) and `translate` (by meaning, opt-in). **Transliterate must NEVER silently fall through to translation** — separate provider chains (`config('...providers.transliterate')` vs `...providers.translate')`). Tests assert this; keep them passing.

## Languages
- `Support\Languages` is the registry over the `languages` config map (10 targets by default: ur ar fa hi mr pa bn ne ru el — trimmed deliberately; more are one config entry away). Script ranges are defined once as hex range pairs and compiled to both a PHP preg pattern and a JS regex source — never hand-write per-engine regexes elsewhere.
- Target resolution: `Languages::resolve()` maps unknown codes to the configured default (service-level defence); controllers **validate first** and 422 on an unregistered `target_lang` before resolving. `target_script_pattern` (default `null`) is a global detection override, kept for back-compat.
- The frontend gets the table via the `window.fatConfig` script injected at `HEAD_END` by the plugin (JSON HEX-escaped; nonce-aware).

## Plugin
- `FilamentAutoTransliteratePlugin` options: `showToggle`, `injectCsrfMeta`, `languageSwitcher`, `toggleRenderHook(string)` (default `GLOBAL_SEARCH_BEFORE`), `scriptNonce(Closure|string|null)` (default `Vite::cspNonce()`).
- **`FilamentAutoTransliteratePlugin::authorizeUsing(?Closure)` is static** (routes are panel-independent): `Request -> bool`, falsy = 403 on every endpoint, enforced by a controller middleware after the route's auth middleware.

## Providers
- One class per backend; implement `Contracts\TranslationProvider`, extend `AbstractProvider`, wire via `provider_map` + per-mode chains in config — never hard-code a provider into the service. Unconfigured providers are skipped. Providers are container **singletons**.
- **Miss vs error:** `TranslationResult::miss()` = provider worked, no answer (unknown word, no candidates, empty/echoed translation, MyMemory "MYMEMORY WARNING"); `::failure()` = provider misbehaved (exception, timeout, any non-2xx incl. 429, malformed payload). Only `isError()` results count towards the circuit breaker; misses just move to the next provider (debug log). All HTTP providers use a 3s connect timeout + `api_timeout`. Google Translate key goes in the `?key=` query param; Microsoft sends `Ocp-Apim-Subscription-Region` only when `microsoft_region` is set.
- Two separate local dictionaries by design: `dictionary_path` (translate mode, meanings) vs `transliterate_dictionary_path` (transliterate mode, romanization) — sharing one file would break the mode-isolation guarantee. `{target}` per-language files; a missing file is a miss.
- The service wraps the chain in a circuit breaker (cache key `fat_provider_cb_{key}`; `provider_failure_threshold` / `provider_failure_cooldown`).
- **Fallback transliteration** (`fallback_transliteration`, off by default): transliterate mode only, **never cached**. `char_fallback_map` is either keyed by language code or a flat legacy map that applies to the default `target_language` only; a target with no map gets no fallback (text unchanged), never another script.
- `min_text_length` is enforced in the service (noop "Text too short"), not just the controller/JS. Exceptions go through `report()`; user text is logged only when `log_requests` is on.

## Cache (gotchas)
- Single table (default `translation_cache`, configurable via `table_name`). Lookup index `fat_cache_lookup_index` on `(original_text_hash, target_language, mode)` is **UNIQUE** — there is **no index on the `original_text` TEXT column**. So always read/write via the hash: `getTranslation()` and `cacheTranslation()` use the hash; never `updateOrCreate()` matching on `original_text` (full table scan). The service's write path goes through `cacheTranslation()` for this reason.
- `cacheTranslation()` is an **atomic conditional upsert**: non-correction sources run `UPDATE ... WHERE source != 'user_correction'`, else INSERT; a unique-violation loser re-reads (a correction retries its update).
- `original_text_hash` is a **MySQL generated column** (`SHA2(original_text,256)`) but a **plain column on SQLite/Postgres**. The model's `saving()` hook populates it on non-MySQL when the text changes OR the hash is null (so adopted/legacy rows get backfilled). Driver-guard any raw DDL in the migration.
- `cacheTranslation()`/`getTranslation()` resolve a null `mode` to the configured default (`config('...mode')`, also the column default) so reads/writes always target one concrete mode and never match across modes.
- Rows with `source = 'user_correction'` (the learn-from-correction loop) are **never overwritten by a non-correction source** — `cacheTranslation()` enforces this; a newer correction may replace an older one. They carry the correcting `user_id` (numeric ids only; plain indexed column, deliberately not a FK).
- Retention: `TranslationCache::prune(days)` / `php artisan filament-auto-transliterate:prune {--days=}` (falls back to `cache_ttl_days`, default null = keep) delete provider rows only, never corrections.
- Migrations ship as **stubs**: `create_translation_cache_table` (fresh installs already include `user_id` + UNIQUE index) and the idempotent upgrade stub `add_user_id_and_unique_index_to_translation_cache_table` (dedupes rows, keeping corrections). Existing installs must publish + run it. Never edit the create stub in a way that diverges from the upgrade stub.

## JS overlay (gotchas)
- In-field loading spinner anchors to Filament's `.fi-input-wrp` wrapper (the input is a replaced element — can't nest inside it). `position: relative` is scoped to a `.fat-loading-host` marker class so Filament layout isn't touched otherwise; trailing edge via `inset-inline-end` (RTL-safe). Falls back to a below-field box for inputs not in `.fi-input-wrp`.
- Spinners are tracked **per host in a Map with a reference count** — concurrent requests on the same field don't stack or prematurely clear. `observeInputs()` sweeps Map entries whose host was detached by a Livewire morph (else a replaced wrapper keeps `fat-loading-host` stuck). Stale-spinner handles from aborted/timed-out requests must not clear newer spinners (generation counter).
- In-flight requests **honour toggle-off**: after the response resolves, the handler bails (no field mutation, no message) if `!this.isEnabled`.
- **Conversion triggers:** Space (keydown, plus an `input`-event fallback for IME/mobile keyboards reporting keyCode 229), textarea Enter, Tab, blur. **Paste is never converted.** Composition-safe (`isComposing`). Tokens are punctuation-aware; emails/URLs/digits/already-target-script text are skipped. Replacement prefers `execCommand('insertText')` so native undo works.
- `applyInline()` replaces the last **whole-token** occurrence (whitespace-bounded), not any substring, to avoid corruption when overlapping requests resolve after text shifts.
- Requests are de-duplicated in flight (`pendingRequests` map), misses are negative-cached per session (FIFO-bounded; as is the word cache), and error cool-downs are kept: 429 → 15s, 401/403/419 → "Session expired" + 30s, 5xx/network → 5s, 422 → negative-cached. Timeout via `AbortController` (`apiTimeout`, default 8000ms). Keep all of this when touching the fetch path. CSRF token: meta tag, else `XSRF-TOKEN` cookie.
- The correction detector only learns the unambiguous case (same token count, exactly one differing token that equals the applied word, replacement in target script) — widen it only with a design-doc update; a wrongly learned pair is worse than a missed one. Correction records expire.
- Singleton guard: re-evaluating the bundle (wire:navigate) calls `refresh()` on the existing instance instead of re-registering listeners. Rescans run on `livewire:navigated` and Livewire `morph.added`/`morph.updated` (debounced).
- The pinned badge is **rendered by JS** (`.fat-pinned-badge`), not Blade. Dark mode keys on Filament's `.dark` class. Converted fields get `dir="auto"`; the overlay is `role="status"`; the header switcher is keyboard-accessible (`aria-pressed`, listbox).
- **Public surface for hosts:** `window.FilamentAutoTransliterate.isEnabled`, `.toggleEnabled(bool)`, `.setTargetLang(code)`, `.getTargetLang()`, `.refresh()`; localStorage keys `fat_enabled` + `fat_target_lang`; `window.fatConfig` (languages/defaultTarget/learnEnabled); and a **window CustomEvent API** (`detail` payloads): `fat:ready`, `fat:applied` `{input, original, applied, targetLang, mode}`, `fat:error` `{input, status}` (0 = network/abort), `fat:toggled` `{enabled}`, `fat:language-changed` `{code}`. Keep the JSDoc header of `translation-overlay.js` and the README "JavaScript API" in sync when changing any of it.

## Endpoint
- Routes under `config('...route.prefix')` (default `filament-auto-transliterate`), named `filament-auto-transliterate.translate` (+ batch/status/stats/learn). **Auth-gated + throttled by default** — it proxies external APIs, so never make it public. `/learn` additionally 404s when `learn.enabled` is off.
- Middleware: `route.middleware = null` (default) builds `['web', 'auth'|'auth:<guard>']` from `route.guard`; an explicit array is used verbatim (legacy, `guard` ignored). Throttling is the **named limiter** `filament-auto-transliterate` (`route.throttle`, "max,minutes", per user else IP; empty = none) plus `filament-auto-transliterate-learn` on `/learn` (`learn.throttle`).
- `allowed_modes` (default both) is validated on translate/batch/learn; an omitted `mode` must still resolve to an allowed default. `/stats` and `/provider-status` are **off by default** (404) behind `ops_endpoints.enabled`; `ops_endpoints.probe_providers` (off) allows live provider calls. `/provider-status` shape is `{configured, circuit_open}` per provider (+ `ok`/`error:'unavailable'` when probing) with top-level `probed` — it changed in 0.3 (breaking); never leak provider/exception text in it.
- `/learn` hardening: `learn.max_original_length`, `learn.min_script_ratio`, original must not already be target script, corrected must be; stores `user_id`. Batch top-level `success` = any item succeeded. Errors go to `report()`; the response detail only shows with `app.debug`.
- User input must stay out of logs unless `log_requests` is explicitly on.

## Install command
- `php artisan filament-auto-transliterate:install` (Spatie InstallCommand) publishes config + migrations and offers to run migrations. Migrations ship as publishable **stubs**, so `php artisan migrate` alone won't create the table until published — the README points users at the install command. Migration publish tag is `filament-auto-transliterate-migrations` (Spatie `shortName()` only strips a `laravel-` prefix, not `filament-`).

## Testing
- Pest + `orchestra/testbench`, in-memory SQLite, 199 tests. Run: `composer test` (`vendor/bin/pest`). Also: `composer lint` (Pint `--test`), `composer format` (Pint), `composer analyse` (PHPStan/Larastan level 5, `phpstan.neon.dist`, `src/` only).
- `Http::preventStrayRequests()` + `Http::fake()` — never hit real networks in tests.
- The DOM/spinner/switcher JS behaviour is NOT covered by the PHP suite — verify spinner/toggle/dropdown/morph/IME behaviour in a real browser.

## CI / branch protection
- `.github/workflows/run-tests.yml` runs on PRs to `main` and `dev` (and pushes to `main`) with these jobs: the `test` matrix (PHP 8.2/8.3/8.4 × Laravel 11/12 × Filament 4/5, prefer-stable = **12 jobs** named `P8.x - L1y.* - F4.*|F5.* - prefer-stable`; plus one experimental, non-blocking `prefer-lowest` run), "Pint (code style)" (also `composer validate --strict`), "PHPStan (static analysis)", and "Dist (assets up to date)".
- **`main` is branch-protected**: the 12 matrix jobs + "Pint (code style)" + "PHPStan (static analysis)" + "Dist (assets up to date)" must be the REQUIRED status checks, `strict` (branch must be up to date), force-push/deletion disabled. Admins are NOT enforced; no required PR review. So the merge gate is CI-green only. (The branch-protection settings live on GitHub, not in the repo — verify they match after changing CI.)
- The matrix job names ARE the required-check contexts — if you change the matrix (PHP/Laravel/Filament versions), update the branch-protection required checks to match, or `main` merges will hang waiting on a check that never reports.
- The separate `fix-php-code-style-issues.yml` runs on `push` with `**.php` paths to every branch **except `main`** and auto-commits Pint fixes — it is NOT a PR gate (that's why a dedicated `pint --test` job exists for protection).
- Dependabot (weekly; github-actions, composer, npm) targets `dev`.
