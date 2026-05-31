<?php

use Iabduul7\FilamentAutoTranslate\Providers\DictionaryProvider;

beforeEach(function () {
    $this->dictPath = sys_get_temp_dir().'/fat-dict-en-ur.json';
    file_put_contents($this->dictPath, json_encode([
        'name' => 'نام',
        'city' => 'شہر',
    ]));
    config(['filament-auto-translate.dictionary_path' => sys_get_temp_dir().'/fat-dict-en-{target}.json']);
    config(['filament-auto-translate.dictionary_max_words' => 3]);
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
    config(['filament-auto-translate.dictionary_max_words' => 1]);

    $result = app(DictionaryProvider::class)->translate('name city', 'en', 'ur');

    expect($result->success)->toBeFalse();
});
