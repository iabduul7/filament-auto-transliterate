<?php

use Iabduul7\FilamentAutoTransliterate\Providers\DictionaryProvider;
use Iabduul7\FilamentAutoTransliterate\Providers\GoogleInputToolsProvider;
use Iabduul7\FilamentAutoTransliterate\Providers\GoogleTranslateProvider;
use Iabduul7\FilamentAutoTransliterate\Providers\LibreTranslateProvider;
use Iabduul7\FilamentAutoTransliterate\Providers\MicrosoftProvider;
use Iabduul7\FilamentAutoTransliterate\Providers\MyMemoryProvider;
use Iabduul7\FilamentAutoTransliterate\Providers\TransliterationDictionaryProvider;

return [
    // Master switch. When false the ->translatable() macro is a no-op.
    'enabled' => env('FILAMENT_AUTO_TRANSLITERATE_ENABLED', true),

    /*
    | Default conversion mode.
    |   transliterate -> same sounds in the target script (Roman Urdu -> Urdu).
    |                    On a miss the text is left unchanged; NEVER falls
    |                    through to meaning-based translation.
    |   translate     -> convert by meaning (English -> Urdu). Opt-in.
    | Override per field: ->translatable(mode: 'translate').
    */
    'mode' => env('FILAMENT_AUTO_TRANSLITERATE_MODE', 'transliterate'),

    'source_language' => env('FILAMENT_AUTO_TRANSLITERATE_SOURCE', 'en'),
    'target_language' => env('FILAMENT_AUTO_TRANSLITERATE_TARGET', 'ur'),

    /*
    | If the typed text already matches this pattern it is assumed to be in the
    | target script and is left untouched (prevents re-converting on edit). Null
    | (the default) defers to the per-language pattern compiled from `languages`
    | below (see Support\Languages::phpScriptPattern()). Set this to a regex to
    | force one global override for every target language.
    */
    'target_script_pattern' => null,

    /*
    | The language registry. One authoritative entry per supported target
    | language, consumed by Support\Languages. `script_ranges` are Unicode block
    | ranges (hex, inclusive) compiled into both a PHP preg pattern and a JS
    | regex source — one definition, no dual maintenance. An optional `itc` key
    | overrides the Google Input Tools input-method code (defaults to
    | "{code}-t-i0-und") for a language whose live itc code differs.
    |
    | Hosts can trim, extend, or re-label this map freely — it's plain config.
    */
    'languages' => [
        'ur' => [
            'label' => 'Urdu',
            'native' => 'اردو',
            'rtl' => true,
            'script_ranges' => [['0600', '06FF'], ['0750', '077F']],
        ],
        'ar' => [
            'label' => 'Arabic',
            'native' => 'العربية',
            'rtl' => true,
            'script_ranges' => [['0600', '06FF'], ['0750', '077F']],
        ],
        'fa' => [
            'label' => 'Persian',
            'native' => 'فارسی',
            'rtl' => true,
            'script_ranges' => [['0600', '06FF'], ['0750', '077F']],
        ],
        'hi' => [
            'label' => 'Hindi',
            'native' => 'हिन्दी',
            'rtl' => false,
            'script_ranges' => [['0900', '097F']],
        ],
        'mr' => [
            'label' => 'Marathi',
            'native' => 'मराठी',
            'rtl' => false,
            'script_ranges' => [['0900', '097F']],
        ],
        'ne' => [
            'label' => 'Nepali',
            'native' => 'नेपाली',
            'rtl' => false,
            'script_ranges' => [['0900', '097F']],
        ],
        'bn' => [
            'label' => 'Bengali',
            'native' => 'বাংলা',
            'rtl' => false,
            'script_ranges' => [['0980', '09FF']],
        ],
        'pa' => [
            'label' => 'Punjabi',
            'native' => 'ਪੰਜਾਬੀ',
            'rtl' => false,
            'script_ranges' => [['0A00', '0A7F']],
        ],
        'ru' => [
            'label' => 'Russian',
            'native' => 'Русский',
            'rtl' => false,
            'script_ranges' => [['0400', '04FF']],
        ],
        'el' => [
            'label' => 'Greek',
            'native' => 'Ελληνικά',
            'rtl' => false,
            'script_ranges' => [['0370', '03FF']],
        ],
    ],

    // HTTP endpoint registration. Auth-gated by default — these routes proxy to
    // external translation APIs and must not be public.
    'route' => [
        'prefix' => env('FILAMENT_AUTO_TRANSLITERATE_PREFIX', 'filament-auto-transliterate'),
        'middleware' => ['web', 'auth'],
        'throttle' => env('FILAMENT_AUTO_TRANSLITERATE_THROTTLE', '60,1'),
    ],

    'api_timeout' => env('FILAMENT_AUTO_TRANSLITERATE_TIMEOUT', 5),

    // Permanent DB cache of every successful conversion. The package owns this
    // table; if a host app already has a `translation_cache` table, override this
    // before running the migration.
    'cache_enabled' => true,
    'table_name' => 'translation_cache',

    /*
    | Map of provider key -> class. Add your own here (must implement the
    | Iabduul7\FilamentAutoTransliterate\Contracts\TranslationProvider contract),
    | then list its key under the relevant mode below.
    */
    'provider_map' => [
        'google_input_tools' => GoogleInputToolsProvider::class,
        'dictionary' => DictionaryProvider::class,
        'transliterate_dictionary' => TransliterationDictionaryProvider::class,
        'mymemory' => MyMemoryProvider::class,
        'libretranslate' => LibreTranslateProvider::class,
        'microsoft' => MicrosoftProvider::class,
        'google' => GoogleTranslateProvider::class,
    ],

    /*
    | Ordered fallback chain per mode. The lists are intentionally separate so a
    | transliterate request can never reach a meaning-based provider. The local
    | transliteration dictionary is checked before the network provider — zero
    | cost, and it's where domain vocabulary (names, honorifics) lives.
    */
    'providers' => [
        'transliterate' => ['transliterate_dictionary', 'google_input_tools'],
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
    'dictionary_path' => env('FILAMENT_AUTO_TRANSLITERATE_DICTIONARY', null),
    'dictionary_max_words' => 3,

    /*
    | Separate dictionary for transliterate mode (a { "roman word": "target
    | script word" } map, {target} placeholder supported). Kept apart from
    | `dictionary_path` above so a meaning-glossary can never answer a phonetic
    | query. Null (the default) makes the provider report unconfigured and skip
    | — zero cost when unused.
    */
    'transliterate_dictionary_path' => env('FILAMENT_AUTO_TRANSLITERATE_TRANSLITERATE_DICTIONARY', null),

    // Number of candidates requested from Google Input Tools per word. The
    // first is applied; the rest are exposed as `alternatives` for future
    // candidate-picker UI. Same request cost regardless of the count.
    'suggestions_per_word' => env('FILAMENT_AUTO_TRANSLITERATE_SUGGESTIONS', 4),

    /*
    | Circuit breaker for flaky free providers. After this many consecutive
    | failures a provider is skipped (not retried) for the cooldown window,
    | so a dead endpoint doesn't cost a timeout on every single request.
    */
    'provider_failure_threshold' => env('FILAMENT_AUTO_TRANSLITERATE_FAILURE_THRESHOLD', 3),
    'provider_failure_cooldown' => env('FILAMENT_AUTO_TRANSLITERATE_FAILURE_COOLDOWN', 120),

    'min_text_length' => 2,
    'max_text_length' => 1000,
    'max_batch_size' => 10,

    /*
    | Learning from corrections (see docs/02-self-improvement.md). When a user
    | fixes an applied word, the pair is stored as a `user_correction` cache row
    | that outranks provider output forever after. Kill switch: when false the
    | /learn route 404s and the JS overlay never sends a correction.
    */
    'learn' => [
        'enabled' => env('FILAMENT_AUTO_TRANSLITERATE_LEARN_ENABLED', true),
    ],

    /*
    | Crude char-by-char transliteration when every provider fails. OFF by
    | default because it produces phonetic nonsense (e.g. "hello" -> garbage).
    | When off, failed conversions leave the user's text unchanged.
    */
    'fallback_transliteration' => env('FILAMENT_AUTO_TRANSLITERATE_FALLBACK', false),
    'char_fallback_map' => [
        'a' => 'ا',
        'b' => 'ب',
        'c' => 'ک',
        'd' => 'د',
        'e' => 'ے',
        'f' => 'ف',
        'g' => 'گ',
        'h' => 'ہ',
        'i' => 'ی',
        'j' => 'ج',
        'k' => 'ک',
        'l' => 'ل',
        'm' => 'م',
        'n' => 'ن',
        'o' => 'و',
        'p' => 'پ',
        'q' => 'ق',
        'r' => 'ر',
        's' => 'س',
        't' => 'ت',
        'u' => 'و',
        'v' => 'و',
        'w' => 'و',
        'x' => 'کس',
        'y' => 'ی',
        'z' => 'ز',
    ],

    // When true, logs activity (including typed text) at debug level. Off by
    // default to keep user input out of logs.
    'log_requests' => env('FILAMENT_AUTO_TRANSLITERATE_LOG', false),
];
