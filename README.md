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

## Installation

```bash
composer require iabduul7/filament-auto-transliterate

# Publishes the config + migration and offers to run migrations.
php artisan filament-auto-transliterate:install

php artisan filament:assets
```

The migration ships as a publishable stub, so use the install command above
(`php artisan migrate` alone won't create the cache table until the migration is
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

Turn the feature on with the header toggle. Focus a marked field, type a Roman word, press space — done. State persists per browser.

### Switching languages

When more than one language is configured, a language chip appears next to the header toggle showing the active language's native name (e.g. اردو). Click it to switch; the choice persists per browser. Fields pinned with `->translatable(target: ...)` show a badge and always use their pinned language. Hide the chip with `->languageSwitcher(false)` on the plugin.

10 languages ship enabled by default — Urdu, Arabic, Persian, Hindi, Marathi, Punjabi, Bengali, Nepali, Russian, and Greek. Trim or extend the list via the `languages` config key (each entry carries its label, native name, RTL flag, and Unicode script ranges used to detect already-converted text) — any other language Google Input Tools supports (Gujarati, Tamil, Telugu, Kannada, Malayalam, Sinhala, Amharic, Hebrew, …) is one config entry away.

### It learns from corrections

When a user fixes a word the package applied (e.g. it wrote مکن and they correct it to مکان), the correction is stored — authenticated and validated — as a high-confidence cache entry that wins over provider output on every future request. The more the package is used, the better and faster it gets, at zero API cost. Disable with `'learn' => ['enabled' => false]`.

## Configuration

Publish the config to customise providers, modes, language, route, and limits:

```bash
php artisan vendor:publish --tag="filament-auto-transliterate-config"
```

Key options:

- **`mode`** — global default (`transliterate` or `translate`).
- **`target_language`** — the default target (defaults to `ur`); users can switch via the header chip, and fields can pin their own.
- **`languages`** — the language registry: which targets are offered, their native names, RTL flags, and script-detection ranges.
- **`providers.transliterate` / `providers.translate`** — the ordered fallback chain for each mode. The lists are separate by design.
- **`provider_map`** — register your own provider (implement `Contracts\TranslationProvider`) and add its key to a chain.
- **`learn.enabled`** — the learn-from-correction loop (on by default).
- **`route.middleware`** — the endpoint is `['web', 'auth']` and throttled by default. It proxies to external translation APIs, so keep it authenticated.

### Providers

Out of the box: Google Input Tools (transliteration), and MyMemory, LibreTranslate, Microsoft, Google plus local JSON dictionaries. Unconfigured providers (missing API keys) are skipped automatically. Every successful conversion is cached permanently in the database, so repeats are instant and free — and user corrections outrank everything.

Free-service resilience is built in: a provider that fails `provider_failure_threshold` times in a row is skipped for `provider_failure_cooldown` seconds instead of eating a timeout per word, the client de-duplicates concurrent requests, remembers misses for the session, and backs off after a 429.

### Local dictionaries

Two separate files, one per mode (so a meaning-glossary can never answer a phonetic query):

```php
// translate mode: meanings
'dictionary_path' => resource_path('dictionaries/en-{target}.json'),

// transliterate mode: romanization → script, checked before any network provider
'transliterate_dictionary_path' => resource_path('dictionaries/roman-{target}.json'),
```

Both are `{ "source word": "target word" }` JSON maps with a `{target}` placeholder to ship one file per language. A dictionary only returns a hit when every word of a short phrase is known, so it never partially mangles input.

## Building assets (contributors)

The compiled JS/CSS ship in `resources/dist`. To rebuild:

```bash
npm install
npm run build
```

## Testing

```bash
composer test
```

## Roadmap

Design documents for shipped and upcoming work live in [`docs/`](docs/).

- ~~Learn-from-correction~~ — shipped (see above).
- ~~First-class support for additional target languages and scripts~~ — shipped (10 languages + header switcher).
- Client-side preload of the most common learned words (no network round-trip at all).
- Candidate picker UI for Google Input Tools alternatives (the API plumbing already returns them).
- A cache/glossary-management Filament resource.

## License

MIT. See [LICENSE.md](LICENSE.md).
