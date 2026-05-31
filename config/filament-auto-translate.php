<?php

use Iabduul7\FilamentAutoTranslate\Providers\DictionaryProvider;
use Iabduul7\FilamentAutoTranslate\Providers\GoogleInputToolsProvider;
use Iabduul7\FilamentAutoTranslate\Providers\GoogleTranslateProvider;
use Iabduul7\FilamentAutoTranslate\Providers\LibreTranslateProvider;
use Iabduul7\FilamentAutoTranslate\Providers\MicrosoftProvider;
use Iabduul7\FilamentAutoTranslate\Providers\MyMemoryProvider;

return [
    // Master switch. When false the ->translatable() macro is a no-op.
    'enabled' => env('FILAMENT_AUTO_TRANSLATE_ENABLED', true),

    /*
    | Default conversion mode.
    |   transliterate -> same sounds in the target script (Roman Urdu -> Urdu).
    |                    On a miss the text is left unchanged; NEVER falls
    |                    through to meaning-based translation.
    |   translate     -> convert by meaning (English -> Urdu). Opt-in.
    | Override per field: ->translatable(mode: 'translate').
    */
    'mode' => env('FILAMENT_AUTO_TRANSLATE_MODE', 'transliterate'),

    'source_language' => env('FILAMENT_AUTO_TRANSLATE_SOURCE', 'en'),
    'target_language' => env('FILAMENT_AUTO_TRANSLATE_TARGET', 'ur'),

    /*
    | If the typed text already matches this pattern it is assumed to be in the
    | target script and is left untouched (prevents re-converting on edit).
    | Default matches the Arabic/Urdu Unicode block. Set to null to disable.
    */
    'target_script_pattern' => '/[\x{0600}-\x{06FF}]/u',

    // HTTP endpoint registration. Auth-gated by default — these routes proxy to
    // external translation APIs and must not be public.
    'route' => [
        'prefix' => env('FILAMENT_AUTO_TRANSLATE_PREFIX', 'filament-auto-translate'),
        'middleware' => ['web', 'auth'],
        'throttle' => env('FILAMENT_AUTO_TRANSLATE_THROTTLE', '60,1'),
    ],

    'api_timeout' => env('FILAMENT_AUTO_TRANSLATE_TIMEOUT', 5),

    // Permanent DB cache of every successful conversion.
    'cache_enabled' => true,
    'table_name' => 'translation_cache',

    /*
    | Map of provider key -> class. Add your own here (must implement the
    | Iabduul7\FilamentAutoTranslate\Contracts\TranslationProvider contract),
    | then list its key under the relevant mode below.
    */
    'provider_map' => [
        'google_input_tools' => GoogleInputToolsProvider::class,
        'dictionary' => DictionaryProvider::class,
        'mymemory' => MyMemoryProvider::class,
        'libretranslate' => LibreTranslateProvider::class,
        'microsoft' => MicrosoftProvider::class,
        'google' => GoogleTranslateProvider::class,
    ],

    /*
    | Ordered fallback chain per mode. The lists are intentionally separate so a
    | transliterate request can never reach a meaning-based provider.
    */
    'providers' => [
        'transliterate' => ['google_input_tools'],
        'translate' => ['dictionary', 'mymemory', 'libretranslate', 'microsoft', 'google'],
    ],

    // Provider credentials (all optional; unconfigured providers are skipped).
    'mymemory_email' => env('MYMEMORY_EMAIL'),
    'libretranslate_url' => env('LIBRETRANSLATE_URL'),
    'libretranslate_key' => env('LIBRETRANSLATE_API_KEY'),
    'microsoft_key' => env('MICROSOFT_TRANSLATOR_KEY'),
    'microsoft_endpoint' => env('MICROSOFT_TRANSLATOR_ENDPOINT'),
    'google_api_key' => env('GOOGLE_TRANSLATE_API_KEY'),

    /*
    | Optional local glossary. A JSON object of { "source word": "target word" }.
    | Use a {target} placeholder to ship one file per language, e.g.
    | resource_path('dictionaries/en-{target}.json'). Null disables it.
    */
    'dictionary_path' => env('FILAMENT_AUTO_TRANSLATE_DICTIONARY', null),
    'dictionary_max_words' => 3,

    'min_text_length' => 2,
    'max_text_length' => 1000,
    'max_batch_size' => 10,

    /*
    | Crude char-by-char transliteration when every provider fails. OFF by
    | default because it produces phonetic nonsense (e.g. "hello" -> garbage).
    | When off, failed conversions leave the user's text unchanged.
    */
    'fallback_transliteration' => env('FILAMENT_AUTO_TRANSLATE_FALLBACK', false),
    'char_fallback_map' => [
        'a' => 'ا', 'b' => 'ب', 'c' => 'ک', 'd' => 'د', 'e' => 'ے',
        'f' => 'ف', 'g' => 'گ', 'h' => 'ہ', 'i' => 'ی', 'j' => 'ج',
        'k' => 'ک', 'l' => 'ل', 'm' => 'م', 'n' => 'ن', 'o' => 'و',
        'p' => 'پ', 'q' => 'ق', 'r' => 'ر', 's' => 'س', 't' => 'ت',
        'u' => 'و', 'v' => 'و', 'w' => 'و', 'x' => 'کس', 'y' => 'ی', 'z' => 'ز',
    ],

    // When true, logs activity (including typed text) at debug level. Off by
    // default to keep user input out of logs.
    'log_requests' => env('FILAMENT_AUTO_TRANSLATE_LOG', false),
];
