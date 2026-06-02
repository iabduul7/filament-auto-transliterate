<?php

use Iabduul7\FilamentAutoTransliterate\Enums\TranslationMode;

it('defaults to transliterate', function () {
    config(['filament-auto-transliterate.mode' => 'transliterate']);

    expect(TranslationMode::default())->toBe(TranslationMode::Transliterate);
});

it('honours the configured default mode', function () {
    config(['filament-auto-transliterate.mode' => 'translate']);

    expect(TranslationMode::default())->toBe(TranslationMode::Translate);
});

it('falls back to transliterate for an invalid configured mode', function () {
    config(['filament-auto-transliterate.mode' => 'nonsense']);

    expect(TranslationMode::default())->toBe(TranslationMode::Transliterate);
});

it('maps each mode to its own provider config key', function () {
    expect(TranslationMode::Transliterate->providersConfigKey())
        ->toBe('filament-auto-transliterate.providers.transliterate')
        ->and(TranslationMode::Translate->providersConfigKey())
        ->toBe('filament-auto-transliterate.providers.translate');
});
