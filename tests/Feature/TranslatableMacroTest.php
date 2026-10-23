<?php

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

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
    $attributes = TextInput::make('name')->translatable()->getExtraInputAttributes();

    expect($attributes['data-fat-translatable'] ?? null)->toBe('true')
        ->and($attributes['data-fat-mode'])->toBe('transliterate')
        ->and($attributes['data-fat-target'])->toBe('ur')
        ->and($attributes['data-fat-endpoint'])->toContain('filament-auto-transliterate/translate')
        ->and($attributes['data-fat-min-length'])->toBe('2')
        ->and($attributes['data-fat-max-length'])->toBe('1000')
        ->and($attributes['data-fat-timeout'])->toBe('8000');
});

it('tags a field with the package data attributes', function () {
    $attributes = TextInput::make('name')->autoTransliterate()->getExtraInputAttributes();

    expect($attributes['data-fat-translatable'] ?? null)->toBe('true')
        ->and($attributes['data-fat-mode'])->toBe('transliterate')
        ->and($attributes['data-fat-target'])->toBe('ur')
        ->and($attributes['data-fat-endpoint'])->toContain('filament-auto-transliterate/translate');
});

it('derives the client timeout from api_timeout plus a connect budget', function () {
    config(['filament-auto-transliterate.api_timeout' => 10]);

    expect(TextInput::make('name')->translatable()->getExtraInputAttributes()['data-fat-timeout'])->toBe('13000');
});

it('honours an explicit translate mode', function () {
    $attributes = TextInput::make('name')->autoTransliterate(mode: 'translate')->getExtraInputAttributes();

    expect($attributes['data-fat-mode'])->toBe('translate');
});

it('follows the global target by default, unpinned', function () {
    $attributes = TextInput::make('name')->translatable()->getExtraInputAttributes();

    expect($attributes['data-fat-learn-endpoint'])->toContain('filament-auto-transliterate/learn')
        ->and($attributes)->not->toHaveKey('data-fat-pinned');
});

it('pins a field to an explicit target language', function () {
    $attributes = TextInput::make('name_hi')->translatable(target: 'hi')->getExtraInputAttributes();

    expect($attributes['data-fat-target'])->toBe('hi')
        ->and($attributes['data-fat-pinned'] ?? null)->toBe('hi');
});

it('resolves an unknown pinned target to the configured default', function () {
    config(['filament-auto-transliterate.target_language' => 'ur']);

    $attributes = TextInput::make('name')->translatable(target: 'not-a-real-language')->getExtraInputAttributes();

    expect($attributes['data-fat-target'])->toBe('ur')
        ->and($attributes['data-fat-pinned'] ?? null)->toBe('ur');
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

it('throws a clear LogicException naming the class on a non-input component', function () {
    expect(fn () => Section::make('General')->translatable())
        ->toThrow(LogicException::class, Section::class)
        ->and(fn () => Section::make('General')->autoTransliterate())
        ->toThrow(LogicException::class, 'input components');
});

it('emits relative route URLs so tenancy and subdomain panels stay same-origin', function () {
    $attributes = TextInput::make('name')->translatable()->getExtraInputAttributes();

    expect($attributes['data-fat-endpoint'])->toBe('/filament-auto-transliterate/translate')
        ->and($attributes['data-fat-learn-endpoint'])->toBe('/filament-auto-transliterate/learn')
        ->and($attributes['data-fat-endpoint'])->not->toContain('http');
});

it('emits only scalar attribute values with no quote, angle bracket or ampersand', function () {
    // ComponentAttributeBag::__toString() does not HTML-escape: it rewrites `"` as `\"`,
    // which truncates the attribute. Nothing we emit may contain such a character.
    foreach ([null, 'hi'] as $target) {
        $attributes = TextInput::make('name')->translatable(target: $target)->getExtraInputAttributes();

        foreach ($attributes as $name => $value) {
            expect((string) $value)->not->toContain('"', "{$name} contains a double quote")
                ->and((string) $value)->not->toContain('<', "{$name} contains <")
                ->and((string) $value)->not->toContain('&', "{$name} contains &");
        }
    }
});

it('does not emit the legacy JSON data-fat-config attribute', function () {
    expect(TextInput::make('name')->translatable()->getExtraInputAttributes())->not->toHaveKey('data-fat-config');
});
