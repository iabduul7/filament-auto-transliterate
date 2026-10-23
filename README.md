# Filament Auto Translate

Inline, as-you-type transliteration and translation for [Filament](https://filamentphp.com) form inputs.

Type Roman Urdu, press space, and the word is rewritten in Urdu script — without leaving the field, opening a modal, or switching keyboards. Built for data-entry teams who think in Urdu (or Hindi, Arabic, Persian, …) but type on a Latin keyboard. 10 target languages ship out of the box, switchable from the panel header.

```
receiver  ->  ریسیور        (transliterate: same sounds, Urdu script)
receiver  ->  وصول کنندہ    (translate: by meaning — opt-in)
```

## Why this exists

The existing Filament translation plugins are **action-based** (click a button, fill a per-locale modal) or automate **static labels**. None of them convert **what the user is typing, as they type it**. This package fills that gap: it is a keyboard-style input helper, not a content-translation workflow.

## How it differs from "translation" plugins

It ships **two distinct modes** and keeps them strictly separate:

| Mode                      | What it does                                                     | On a miss                  |
| ------------------------- | ---------------------------------------------------------------- | -------------------------- |
| `transliterate` (default) | Writes the same sounds in the target script (Roman Urdu to Urdu) | Leaves your text unchanged |
| `translate` (opt-in)      | Converts by meaning (English to Urdu)                            | Leaves your text unchanged |

Transliterate mode **never silently falls through** to meaning-based translation. That separation is the whole point: a phonetic helper that quietly "translates" a word it didn't recognise is worse than one that leaves it alone.

## Requirements

| Dependency | Version                          |
| ---------- | -------------------------------- |
| PHP        | 8.2+ (CI: 8.2, 8.3, 8.4)         |
| Laravel    | 11.x, 12.x (CI-tested)           |
| Filament   | 4.x or 5.x (both CI-tested)      |

## Installation

```bash
composer require iabduul7/filament-auto-transliterate

# Publishes the config + migrations and offers to run migrations.
php artisan filament-auto-transliterate:install

php artisan filament:assets
```

The migrations ship as publishable stubs, so use the install command above
(`php artisan migrate` alone won't create the cache table until the migrations are
published). To publish manually instead:

```bash
php artisan vendor:publish --tag="filament-auto-transliterate-migrations"
php artisan migrate
```

Add the plugin to a panel:

```php
use Iabduul7\FilamentAutoTransliterate\FilamentAutoTransliteratePlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(FilamentAutoTransliteratePlugin::make());
}
```

That registers the header on/off toggle (next to global search) and injects the assets. Nothing else to wire up.

### Plugin options

```php
FilamentAutoTransliteratePlugin::make()
    ->showToggle(false)                               // hide the header on/off toggle
    ->languageSwitcher(false)                         // hide the language chip/dropdown
    ->injectCsrfMeta(false)                           // your layout already has <meta name="csrf-token">
    ->toggleRenderHook(PanelsRenderHook::TOPBAR_END)  // move the toggle
    ->scriptNonce(fn () => Vite::cspNonce());         // CSP nonce for the inline config script
```

| Option | Default | Purpose |
| ------ | ------- | ------- |
| `showToggle(bool)` | `true` | Render the header on/off toggle. |
| `languageSwitcher(bool)` | `true` | Show the language chip when more than one language is configured. |
| `injectCsrfMeta(bool)` | `true` | Inject `<meta name="csrf-token">`. The overlay also falls back to the `XSRF-TOKEN` cookie. |
| `toggleRenderHook(string)` | `PanelsRenderHook::GLOBAL_SEARCH_BEFORE` | Render hook for the toggle + chip. Move it for custom topbars, sidebar-only or simple-layout panels. |
| `scriptNonce(Closure\|string\|null)` | `Vite::cspNonce()` | Nonce for the inline `window.fatConfig` script under a strict CSP. |
| `FilamentAutoTransliteratePlugin::authorizeUsing(?Closure)` | `null` | **Static** host gate for every endpoint. Receives the `Request`; a falsy return yields a 403. Call it from a service provider's `boot()`. |

```php
use Illuminate\Http\Request;

FilamentAutoTransliteratePlugin::authorizeUsing(
    fn (Request $request) => $request->user()?->can('use-transliteration')
);
```

## Usage

Mark any text field, textarea, etc. as translatable:

```php
use Filament\Forms\Components\TextInput;

TextInput::make('receiver_name')
    ->translatable();                 // uses the default mode

TextInput::make('description')
    ->translatable(mode: 'translate'); // convert by meaning instead

TextInput::make('name_hi')
    ->translatable(target: 'hi');      // pinned to Hindi, ignores the header switcher
```

`->autoTransliterate()` is an identical alias, for hosts that already define their own `translatable` macro. Both throw a `LogicException` on components that aren't inputs (no `extraInputAttributes()`).

Turn the feature on with the header toggle. Focus a marked field, type a Roman word, press space — done. State persists per browser.

When a word is converted:

- **Triggers:** space, Enter in a textarea, Tab, and blur. Pasted text is left untouched.
- **Skipped:** emails, URLs, numbers, and text already in the target script.
- **Safe to type with:** IME/composition-aware, works with mobile keyboards, and native undo (Ctrl/Cmd+Z) still reverts a conversion.

### Switching languages

When more than one language is configured, a language chip appears next to the header toggle showing the active language's native name (e.g. اردو). Click it to switch (it is keyboard-accessible); the choice persists per browser. Fields pinned with `->translatable(target: ...)` show a badge and always use their pinned language. Hide the chip with `->languageSwitcher(false)` on the plugin.

10 languages ship enabled by default — Urdu, Arabic, Persian, Hindi, Marathi, Punjabi, Bengali, Nepali, Russian, and Greek. Trim or extend the list via the `languages` config key (each entry carries its label, native name, RTL flag, and Unicode script ranges used to detect already-converted text) — any other language Google Input Tools supports (Gujarati, Tamil, Telugu, Kannada, Malayalam, Sinhala, Amharic, Hebrew, …) is one config entry away.

### It learns from corrections

When a user fixes a word the package applied (e.g. it wrote مکن and they correct it to مکان), the correction is stored — authenticated, validated, and stamped with the correcting user's id — as a high-confidence cache entry that wins over provider output on every future request. The more the package is used, the better and faster it gets, at zero API cost. Disable with `'learn' => ['enabled' => false]`.

## Configuration

Publish the config to customise providers, modes, language, route, and limits:

```bash
php artisan vendor:publish --tag="filament-auto-transliterate-config"
```

### Configuration reference

Every key in `config/filament-auto-transliterate.php`:

| Key | Default | Env | Purpose |
| --- | ------- | --- | ------- |
| `enabled` | `true` | `FILAMENT_AUTO_TRANSLITERATE_ENABLED` | Master switch; when false `->translatable()` is a no-op. |
| `mode` | `transliterate` | `FILAMENT_AUTO_TRANSLITERATE_MODE` | Global default mode (`transliterate` or `translate`). |
| `source_language` | `en` | `FILAMENT_AUTO_TRANSLITERATE_SOURCE` | Source language passed to providers. |
| `target_language` | `ur` | `FILAMENT_AUTO_TRANSLITERATE_TARGET` | Default target; users switch via the header chip, fields can pin their own. |
| `target_script_pattern` | `null` | - | Global regex override for "already in target script" detection; `null` uses the per-language pattern. |
| `languages` | 10 languages | - | Language registry: label, native name, RTL flag, Unicode `script_ranges`, optional `itc` override. |
| `route.prefix` | `filament-auto-transliterate` | `FILAMENT_AUTO_TRANSLITERATE_PREFIX` | URL prefix for the endpoints. |
| `route.guard` | `null` | `FILAMENT_AUTO_TRANSLITERATE_GUARD` | Auth guard; builds `['web', 'auth:<guard>']`. `null` = default guard. |
| `route.middleware` | `null` | - | `null` builds the default stack. An explicit array is used verbatim and `guard` is then ignored. |
| `route.throttle` | `60,1` | `FILAMENT_AUTO_TRANSLITERATE_THROTTLE` | Per-user (fallback: IP) limit as `max,minutes`; empty = no limit. |
| `allowed_modes` | `['transliterate', 'translate']` | - | Modes the endpoints accept; anything else is a 422. Use `['transliterate']` to protect paid provider quota. |
| `ops_endpoints.enabled` | `false` | `FILAMENT_AUTO_TRANSLITERATE_OPS_ENABLED` | Enable `/stats` and `/provider-status` (404 otherwise). |
| `ops_endpoints.probe_providers` | `false` | `FILAMENT_AUTO_TRANSLITERATE_OPS_PROBE` | Let `/provider-status` make live calls to every provider (can hit paid APIs). |
| `api_timeout` | `5` | `FILAMENT_AUTO_TRANSLITERATE_TIMEOUT` | Seconds per provider HTTP request (connect timeout is a fixed 3s). |
| `cache_enabled` | `true` | - | Permanently cache successful conversions in the database. |
| `table_name` | `translation_cache` | - | Cache table name; override before migrating if it clashes. |
| `cache_ttl_days` | `null` | `FILAMENT_AUTO_TRANSLITERATE_CACHE_TTL_DAYS` | Age after which `filament-auto-transliterate:prune` deletes provider rows (corrections are never pruned). `null` = keep forever. |
| `provider_map` | 7 providers | - | Provider key => class. Add your own here. |
| `providers.transliterate` | `['transliterate_dictionary', 'google_input_tools']` | - | Ordered fallback chain for transliterate mode. |
| `providers.translate` | `['dictionary', 'mymemory', 'libretranslate', 'microsoft', 'google']` | - | Ordered fallback chain for translate mode. |
| `mymemory_email` | `null` | `MYMEMORY_EMAIL` | Raises the MyMemory free quota. |
| `libretranslate_url` | `null` | `LIBRETRANSLATE_URL` | LibreTranslate server URL. |
| `libretranslate_key` | `null` | `LIBRETRANSLATE_API_KEY` | LibreTranslate API key. |
| `microsoft_key` | `null` | `MICROSOFT_TRANSLATOR_KEY` | Azure Translator key. |
| `microsoft_endpoint` | `null` | `MICROSOFT_TRANSLATOR_ENDPOINT` | Azure Translator endpoint. |
| `microsoft_region` | `null` | `MICROSOFT_TRANSLATOR_REGION` | Azure region (e.g. `westeurope`), sent only when set; needed for regional / multi-service resources. |
| `google_api_key` | `null` | `GOOGLE_TRANSLATE_API_KEY` | Google Cloud Translation key. |
| `dictionary_path` | `null` | `FILAMENT_AUTO_TRANSLITERATE_DICTIONARY` | Translate-mode glossary JSON (`{target}` placeholder supported). |
| `dictionary_max_words` | `3` | - | Longest phrase (in words) the dictionaries attempt. |
| `transliterate_dictionary_path` | `null` | `FILAMENT_AUTO_TRANSLITERATE_TRANSLITERATE_DICTIONARY` | Transliterate-mode romanization JSON, checked before any network provider. |
| `suggestions_per_word` | `4` | `FILAMENT_AUTO_TRANSLITERATE_SUGGESTIONS` | Candidates requested from Google Input Tools; the extras are returned as `alternatives`. |
| `provider_failure_threshold` | `3` | `FILAMENT_AUTO_TRANSLITERATE_FAILURE_THRESHOLD` | Consecutive provider **errors** before the circuit breaker opens. |
| `provider_failure_cooldown` | `120` | `FILAMENT_AUTO_TRANSLITERATE_FAILURE_COOLDOWN` | Seconds an open breaker skips the provider. |
| `min_text_length` | `2` | - | Shorter text is a server-side no-op ("Text too short"). |
| `max_text_length` | `1000` | - | Maximum text length per item (422 above). |
| `max_batch_size` | `10` | - | Maximum items per batch request. |
| `learn.enabled` | `true` | `FILAMENT_AUTO_TRANSLITERATE_LEARN_ENABLED` | Learn-from-correction loop; `/learn` 404s when off. |
| `learn.max_original_length` | `200` | `FILAMENT_AUTO_TRANSLITERATE_LEARN_MAX_ORIGINAL` | Longest `original` a correction may carry. |
| `learn.throttle` | `20,1` | `FILAMENT_AUTO_TRANSLITERATE_LEARN_THROTTLE` | Stricter extra limit for `/learn` (`max,minutes`; empty = off). |
| `learn.min_script_ratio` | `1.0` | - | Share of the correction's letters that must be in the target script. |
| `fallback_transliteration` | `false` | `FILAMENT_AUTO_TRANSLITERATE_FALLBACK` | Crude char-by-char fallback when every provider misses. Transliterate mode only, never cached. |
| `char_fallback_map` | Urdu letter map | - | Flat map (applies to the default `target_language` only) or keyed by language code (`['ur' => [...], 'hi' => [...]]`). A target without a map gets no fallback. |
| `log_requests` | `false` | `FILAMENT_AUTO_TRANSLITERATE_LOG` | Debug-log activity **including typed text**. Keep off in production. |

### Providers

Out of the box: Google Input Tools (transliteration), and MyMemory, LibreTranslate, Microsoft, Google plus local JSON dictionaries. Unconfigured providers (missing API keys) are skipped automatically. Every successful conversion is cached permanently in the database, so repeats are instant and free — and user corrections outrank everything. Add your own by implementing `Contracts\TranslationProvider`, registering it in `provider_map`, and adding its key to a mode's chain.

Free-service resilience is built in. A provider outcome is either a **miss** (it worked but has no answer: unknown word, no candidates, quota-warning text) or an **error** (transport failure, timeout, any non-2xx including 429, malformed payload). Only errors count towards the circuit breaker: a provider that errors `provider_failure_threshold` times in a row is skipped for `provider_failure_cooldown` seconds instead of eating a timeout per word. The client de-duplicates concurrent requests, remembers misses for the session, and backs off after 429 / auth / server errors.

### Local dictionaries

Two separate files, one per mode (so a meaning-glossary can never answer a phonetic query):

```php
// translate mode: meanings
'dictionary_path' => resource_path('dictionaries/en-{target}.json'),

// transliterate mode: romanization → script, checked before any network provider
'transliterate_dictionary_path' => resource_path('dictionaries/roman-{target}.json'),
```

Both are `{ "source word": "target word" }` JSON maps with a `{target}` placeholder to ship one file per language. A missing file is a miss, not an error. A dictionary only returns a hit when every word of a short phrase is known, so it never partially mangles input.

### Cache maintenance

Provider results can be pruned by age; user corrections never are:

```bash
php artisan filament-auto-transliterate:prune --days=90   # or set cache_ttl_days and omit --days
```

## HTTP API

Five routes under `route.prefix` (default `filament-auto-transliterate`), named `filament-auto-transliterate.{translate,batch,learn,status,stats}`. All are auth-gated (`web` + `auth`, or `auth:<guard>`), optionally gated again by `authorizeUsing()`, and rate-limited by the named limiter `filament-auto-transliterate` (`route.throttle`; `/learn` also gets `filament-auto-transliterate-learn`). They are JSON endpoints that use Laravel's CSRF protection.

| Method | Path | Purpose |
| ------ | ---- | ------- |
| `POST` | `/translate` | Convert one text. |
| `POST` | `/batch-translate` | Convert up to `max_batch_size` texts. |
| `POST` | `/learn` | Store a user correction (404 when `learn.enabled` is false). |
| `GET`  | `/provider-status` | Provider health (404 unless `ops_endpoints.enabled`). |
| `GET`  | `/stats` | Cache statistics (404 unless `ops_endpoints.enabled`). |

**`POST /translate`** — body `{ "text": "receiver", "target_lang": "ur", "mode": "transliterate" }` (`target_lang` and `mode` optional; `target_lang` must be a configured language code, `mode` must be in `allowed_modes`).

```json
{ "success": true, "translated": "ریسیور", "source": "google_input_tools", "confidence": 0.9,
  "processing_time": 120.5, "original": null, "message": null, "alternatives": ["رسیور"] }
```

A no-op or miss is `success: false` with the original text and a `message` ("No translation needed", "Text too short", "No translation available"); the field is simply left unchanged.

**`POST /batch-translate`** — body `{ "texts": ["a", "b"], "target_lang": "ur", "mode": "transliterate" }`.

```json
{ "success": true, "results": { "0": { "...": "per-item result as above" } }, "total": 2, "successful": 1 }
```

Top-level `success` is true when **at least one** item succeeded.

**`POST /learn`** — body `{ "original": "makan", "corrected": "مکان", "target_lang": "ur", "mode": "transliterate" }`. `original` must not already be in the target script (max `learn.max_original_length`), `corrected` must differ and be in the target script (`learn.min_script_ratio`). Returns `{ "success": true, "target_lang": "ur", "mode": "transliterate" }`.

**`GET /provider-status`** — one entry per `provider_map` key:

```json
{ "success": true, "probed": false, "timestamp": "...",
  "providers": { "mymemory": { "configured": true, "circuit_open": false } } }
```

With `ops_endpoints.probe_providers` on, each entry also has `ok` (bool) and `error` (`null` or `"unavailable"`) from a live test call, and `probed` is `true`. Provider/exception text is never returned.

**Errors**

| Status | Meaning |
| ------ | ------- |
| 401 / 403 | Not authenticated, or `authorizeUsing()` returned falsy. |
| 404 | Disabled route (`learn.enabled`, `ops_endpoints.enabled`). |
| 419 | Missing/expired CSRF token. |
| 422 | `{ "success": false, "message": "Invalid input", "errors": {...} }` — validation, unknown `target_lang`, or a mode outside `allowed_modes`. |
| 429 | Rate limit exceeded (`route.throttle` / `learn.throttle`). |
| 500 | `{ "success": false, "message": "...", "error": "Internal server error" }`; the detail is shown only when `app.debug` is on, and the exception goes to `report()`. |

## JavaScript API

The overlay is a singleton at `window.FilamentAutoTransliterate` (re-evaluating the bundle on `wire:navigate` reuses it).

| Member | Purpose |
| ------ | ------- |
| `.isEnabled` | Whether conversion is currently on. |
| `.toggleEnabled(bool)` | Turn conversion on/off (same as the header toggle). |
| `.setTargetLang(code)` / `.getTargetLang()` | Set/read the active target language. |
| `.refresh()` | Re-scan the DOM for tagged inputs. |

**Storage:** `localStorage` keys `fat_enabled` (`"true"`/`"false"`) and `fat_target_lang`.

**`window.fatConfig`** — injected at `HEAD_END`: `{ languages, defaultTarget, learnEnabled }`.

**Events** — `CustomEvent`s dispatched on `window`; the payload is in `event.detail`:

| Event | Detail |
| ----- | ------ |
| `fat:ready` | `{}` — instance constructed or refreshed. |
| `fat:applied` | `{ input, original, applied, targetLang, mode }` — a conversion was written into a field. |
| `fat:error` | `{ input, status }` — request failed; `status` is the HTTP status, or `0` for network error/timeout. |
| `fat:toggled` | `{ enabled }` |
| `fat:language-changed` | `{ code }` |

```js
window.addEventListener('fat:applied', (e) => console.log(e.detail.original, '->', e.detail.applied));
```

**Field attributes** — set by the macro, read by the overlay:

| Attribute | Value |
| --------- | ----- |
| `data-fat-translatable` | `"true"` marks the input. |
| `data-fat-endpoint` | Relative translate route URL (fields without it are skipped). |
| `data-fat-learn-endpoint` | Relative learn route URL. |
| `data-fat-target` | Target language code. |
| `data-fat-mode` | `transliterate` or `translate`. |
| `data-fat-min-length` / `data-fat-max-length` | Text length bounds (integers). |
| `data-fat-timeout` | Client request timeout in ms (`api_timeout` + 3s, default 8000). |
| `data-fat-pinned` | Language code, present only on fields pinned via `->translatable(target: ...)`. |

There is intentionally no JSON attribute: Laravel's attribute bag does not HTML-escape values (it turns `"` into `\"`), which would truncate it. Inputs tagged by hand with a legacy `data-fat-config` JSON attribute (and no `data-fat-endpoint`) are still read.

Client error handling: 401/403/419 shows "Session expired" and pauses requests for 30s; 429 pauses for 15s; 5xx/network errors pause for 5s; 422 is remembered as a miss.

## Security and privacy

- **Typed text leaves your server.** It is sent to whichever providers you configure (Google, Microsoft, MyMemory, LibreTranslate, …). Use only providers you are comfortable sharing it with, or restrict the chains to the local dictionary providers.
- The endpoints are an auth-gated, throttled proxy to those APIs — keep them authenticated. Restrict `allowed_modes` to `['transliterate']` if you don't want users spending translate-provider quota.
- Nothing is logged unless `log_requests` is explicitly on; provider/exception text never reaches API responses.
- Provider API keys stay in your server config and are never sent to the browser.
- Cached conversions and corrections live in your own database table.

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md).

## Upgrading to 0.3

1. **Publish and run the new migration** (adds `user_id` and makes the cache lookup index UNIQUE; duplicates are collapsed, keeping corrections; it is a no-op on fresh installs):

   ```bash
   php artisan vendor:publish --tag="filament-auto-transliterate-migrations"
   php artisan migrate
   ```

   Only the `add_user_id_and_unique_index_to_translation_cache_table` stub is new; skip re-publishing the create migration if you already have it.
2. **`/provider-status` response shape changed (breaking):** each provider is now `{ configured, circuit_open }` (plus `ok` / `error` when probing) and the response has a top-level `probed`. Update anything consuming the old shape.
3. **`/stats` and `/provider-status` are off by default.** Set `ops_endpoints.enabled` to bring them back; live probing needs `ops_endpoints.probe_providers`.
4. **`route.middleware` now defaults to `null`** (stack built from `route.guard`). A published config with an explicit array keeps working verbatim; to use the new `guard` option, set `middleware` to `null`. Throttling now comes from the named limiter / `route.throttle`.
5. **`allowed_modes`** is new (default: both modes). Requests with a mode outside it get a 422.
6. Run `php artisan filament:assets` so the new JS/CSS is served, and re-publish the config if you want the new keys visible (all have defaults).

## Troubleshooting

| Symptom | Fix |
| ------- | --- |
| `Table translation_cache doesn't exist` | Run `php artisan filament-auto-transliterate:install` (or publish the migrations, then migrate). `migrate` alone won't find the stubs. |
| Old behaviour / missing UI after upgrade | Stale assets: `php artisan filament:assets`, then hard-refresh. |
| 419 on every request | No CSRF token on the page. Keep `injectCsrfMeta(true)` (default), or ensure your layout has `<meta name="csrf-token">`. |
| 401/403, "Session expired" | The panel uses a non-default guard: set `route.guard` (or `route.middleware`) to match it. A 403 can also come from your `authorizeUsing()` callback. |
| Inline config script blocked | Strict CSP: provide a nonce with `->scriptNonce(...)` (or `Vite::useCspNonce()`). |
| Toggle missing in the header | Custom topbar/layout: pick another hook with `->toggleRenderHook(...)`. |
| `/stats` or `/provider-status` return 404 | Enable `ops_endpoints.enabled`. |

## Development

```bash
composer install && npm install

composer test      # Pest (199 tests, in-memory SQLite, all HTTP faked)
composer lint      # Pint, check only
composer format    # Pint, apply fixes
composer analyse   # PHPStan / Larastan, level 5

npm run build      # rebuild resources/dist (committed; CI fails if stale)
```

The compiled JS/CSS ship in `resources/dist`. See [CONTRIBUTING.md](CONTRIBUTING.md) for the branch flow.

## Roadmap

Design documents for shipped and upcoming work live in [`docs/`](docs/).

- ~~Learn-from-correction~~ — shipped (see above).
- ~~First-class support for additional target languages and scripts~~ — shipped (10 languages + header switcher).
- Client-side preload of the most common learned words (no network round-trip at all).
- Candidate picker UI for Google Input Tools alternatives (the API plumbing already returns them).
- A cache/glossary-management Filament resource.

## License

MIT. See [LICENSE.md](LICENSE.md).
