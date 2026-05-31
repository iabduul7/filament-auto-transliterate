<?php

use Iabduul7\FilamentAutoTranslate\Enums\TranslationMode;

it('defaults to transliterate', function () {
    config(['filament-auto-translate.mode' => 'transliterate']);

    expect(TranslationMode::default())->toBe(TranslationMode::Transliterate);
});

it('honours the configured default mode', function () {
    config(['filament-auto-translate.mode' => 'translate']);

    expect(TranslationMode::default())->toBe(TranslationMode::Translate);
});

it('falls back to transliterate for an invalid configured mode', function () {
    config(['filament-auto-translate.mode' => 'nonsense']);

    expect(TranslationMode::default())->toBe(TranslationMode::Transliterate);
});

it('maps each mode to its own provider config key', function () {
    expect(TranslationMode::Transliterate->providersConfigKey())
        ->toBe('filament-auto-translate.providers.transliterate')
        ->and(TranslationMode::Translate->providersConfigKey())
        ->toBe('filament-auto-translate.providers.translate');
});
