# Filament Auto Translate

Inline, as-you-type transliteration and translation for [Filament](https://filamentphp.com) form inputs.

Type Roman Urdu, press space, and the word is rewritten in Urdu script — without leaving the field, opening a modal, or switching keyboards. Built for data-entry teams who think in Urdu but type on a Latin keyboard.

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
```

Turn the feature on with the header toggle. Focus a marked field, type a Roman word, press space — done. State persists per browser.

## Configuration

Publish the config to customise providers, modes, language, route, and limits:

```bash
php artisan vendor:publish --tag="filament-auto-transliterate-config"
```

Key options:

- **`mode`** — global default (`transliterate` or `translate`).
- **`target_language`** — defaults to `ur`. The architecture is language-agnostic; v1 ships Urdu defaults.
- **`providers.transliterate` / `providers.translate`** — the ordered fallback chain for each mode. The lists are separate by design.
- **`provider_map`** — register your own provider (implement `Contracts\TranslationProvider`) and add its key to a chain.
- **`route.middleware`** — the endpoint is `['web', 'auth']` and throttled by default. It proxies to external translation APIs, so keep it authenticated.

### Providers

Out of the box: Google Input Tools (transliteration), and MyMemory, LibreTranslate, Microsoft, Google plus a local JSON dictionary (translation). Unconfigured providers (missing API keys) are skipped automatically. Every successful conversion is cached permanently in the database, so repeats are instant and free.

### Local dictionary

Point `dictionary_path` at a JSON file of `{ "source word": "target word" }`. Use a `{target}` placeholder to ship one file per language:

```php
'dictionary_path' => resource_path('dictionaries/en-{target}.json'),
```

The dictionary is checked before any network provider and only returns a hit when every word of a short phrase is known, so it never partially mangles input.

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

- Learn-from-correction: when a user fixes an applied word, remember it next time.
- Client-side fast path for the most common words (no network round-trip).
- A cache-management Filament resource.
- First-class support for additional target languages and scripts.

## License

MIT. See [LICENSE.md](LICENSE.md).
