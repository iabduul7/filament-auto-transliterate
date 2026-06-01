<?php

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/*
| The package registers `translatable()` as its primary macro on Filament
| components, plus an `autoTransliterate()` alias with identical behaviour for
| hosts that already define their own `translatable` macro.
*/

it('registers both the translatable and autoTransliterate macros', function () {
    expect(Component::hasMacro('translatable'))->toBeTrue()
        ->and(Component::hasMacro('autoTransliterate'))->toBeTrue();
});

it('tags a field via the translatable macro', function () {
    $input = TextInput::make('name')->translatable();

    $attributes = $input->getExtraInputAttributes();

    expect($attributes['data-fat-translatable'] ?? null)->toBe('true')
        ->and($attributes)->toHaveKey('data-fat-config');

    $config = json_decode($attributes['data-fat-config'], true);
    expect($config['mode'])->toBe('transliterate')
        ->and($config['targetLang'])->toBe('ur')
        ->and($config['endpoint'])->toContain('filament-auto-transliterate/translate');
});

it('tags a field with the package data attributes', function () {
    $input = TextInput::make('name')->autoTransliterate();

    $attributes = $input->getExtraInputAttributes();

    expect($attributes['data-fat-translatable'] ?? null)->toBe('true')
        ->and($attributes)->toHaveKey('data-fat-config');

    $config = json_decode($attributes['data-fat-config'], true);
    expect($config['mode'])->toBe('transliterate')
        ->and($config['targetLang'])->toBe('ur')
        ->and($config['endpoint'])->toContain('filament-auto-transliterate/translate');
});

it('honours an explicit translate mode', function () {
    $input = TextInput::make('name')->autoTransliterate(mode: 'translate');

    $config = json_decode($input->getExtraInputAttributes()['data-fat-config'], true);

    expect($config['mode'])->toBe('translate');
});

it('is a no-op when disabled via the argument', function () {
    $input = TextInput::make('name')->autoTransliterate(false);

    expect($input->getExtraInputAttributes())->not->toHaveKey('data-fat-translatable');
});

it('is a no-op when the package is globally disabled', function () {
    config(['filament-auto-transliterate.enabled' => false]);

    $input = TextInput::make('name')->autoTransliterate();

    expect($input->getExtraInputAttributes())->not->toHaveKey('data-fat-translatable');
});
