<?php

use Iabduul7\FilamentAutoTransliterate\Providers\DictionaryProvider;

beforeEach(function () {
    $this->dictPath = sys_get_temp_dir().'/fat-dict-en-ur.json';
    file_put_contents($this->dictPath, json_encode([
        'name' => 'نام',
        'city' => 'شہر',
    ]));
    config(['filament-auto-transliterate.dictionary_path' => sys_get_temp_dir().'/fat-dict-en-{target}.json']);
    config(['filament-auto-transliterate.dictionary_max_words' => 3]);
});

afterEach(function () {
    @unlink($this->dictPath);
});

it('resolves the {target} placeholder and is configured', function () {
    expect(app(DictionaryProvider::class)->isConfigured())->toBeTrue();
});

it('returns a hit when every word is known', function () {
    $result = app(DictionaryProvider::class)->translate('name city', 'en', 'ur');

    expect($result->success)->toBeTrue()
        ->and($result->translated)->toBe('نام شہر')
        ->and($result->source)->toBe('dictionary');
});

it('fails (rather than partially mangling) when a word is unknown', function () {
    $result = app(DictionaryProvider::class)->translate('name unknownword', 'en', 'ur');

    expect($result->success)->toBeFalse();
});

it('fails when the phrase exceeds the word limit', function () {
    config(['filament-auto-transliterate.dictionary_max_words' => 1]);

    $result = app(DictionaryProvider::class)->translate('name city', 'en', 'ur');

    expect($result->success)->toBeFalse();
});

it('reports a missing word, long phrase and missing per-target file as misses, not errors', function () {
    $unknown = app(DictionaryProvider::class)->translate('name unknownword', 'en', 'ur');
    expect($unknown->isMiss())->toBeTrue()->and($unknown->isError())->toBeFalse();

    config(['filament-auto-transliterate.dictionary_max_words' => 1]);
    $long = app(DictionaryProvider::class)->translate('name city', 'en', 'ur');
    expect($long->isMiss())->toBeTrue();

    $noFile = app(DictionaryProvider::class)->translate('name', 'en', 'hi');
    expect($noFile->isMiss())->toBeTrue()->and($noFile->isError())->toBeFalse();
});

it('stays configured when only some languages have a file', function () {
    config(['filament-auto-transliterate.target_language' => 'hi']);

    // No en-hi file exists, but the placeholder directory does.
    expect(app(DictionaryProvider::class)->isConfigured())->toBeTrue();
});

it('is not configured without a path, or when a literal file is missing', function () {
    config(['filament-auto-transliterate.dictionary_path' => null]);
    expect(app(DictionaryProvider::class)->isConfigured())->toBeFalse();

    config(['filament-auto-transliterate.dictionary_path' => sys_get_temp_dir().'/fat-definitely-missing.json']);
    expect(app(DictionaryProvider::class)->isConfigured())->toBeFalse();
});

it('matches case-insensitively and collapses repeated whitespace', function () {
    $result = app(DictionaryProvider::class)->translate("  NAME   \t City ", 'en', 'ur');

    expect($result->success)->toBeTrue()->and($result->translated)->toBe('نام شہر');
});

it('reports a corrupt dictionary file as an error', function () {
    $path = sys_get_temp_dir().'/fat-dict-en-xx.json';
    file_put_contents($path, '{not json');
    config(['filament-auto-transliterate.dictionary_path' => sys_get_temp_dir().'/fat-dict-en-{target}.json']);

    $result = app(DictionaryProvider::class)->translate('name', 'en', 'xx');
    @unlink($path);

    expect($result->isError())->toBeTrue();
});
