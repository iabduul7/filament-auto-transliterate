<?php

use Iabduul7\FilamentAutoTransliterate\Providers\TransliterationDictionaryProvider;

/*
| A separate dictionary for transliterate mode, kept apart from
| DictionaryProvider's `dictionary_path` so a meaning-glossary can never
| answer a phonetic query. See docs/04-free-tier-quality.md.
*/

beforeEach(function () {
    $this->dictPath = sys_get_temp_dir().'/fat-translit-dict-en-ur.json';
    file_put_contents($this->dictPath, json_encode([
        'mkan' => 'مکان',
    ]));
    config(['filament-auto-transliterate.transliterate_dictionary_path' => sys_get_temp_dir().'/fat-translit-dict-en-{target}.json']);
    // The meaning-glossary path is deliberately left configured to a
    // different file to prove the two never mix.
    config(['filament-auto-transliterate.dictionary_path' => null]);
});

afterEach(function () {
    @unlink($this->dictPath);
});

it('uses its own key and config path, distinct from the translate dictionary', function () {
    $provider = app(TransliterationDictionaryProvider::class);

    expect($provider->key())->toBe('transliterate_dictionary')
        ->and($provider->isConfigured())->toBeTrue();
});

it('is unconfigured (and skipped) when no path is set', function () {
    config(['filament-auto-transliterate.transliterate_dictionary_path' => null]);

    expect(app(TransliterationDictionaryProvider::class)->isConfigured())->toBeFalse();
});

it('resolves a known romanized word from the transliteration dictionary', function () {
    $result = app(TransliterationDictionaryProvider::class)->translate('mkan', 'en', 'ur');

    expect($result->success)->toBeTrue()
        ->and($result->translated)->toBe('مکان')
        ->and($result->source)->toBe('transliterate_dictionary');
});
