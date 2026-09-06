<?php

use Iabduul7\FilamentAutoTransliterate\Support\Languages;

/*
| Languages is the authoritative registry for the `languages` config map
| (docs/01-multi-language.md). Every target-language lookup elsewhere in the
| package goes through it, so an unknown/garbage code can never reach a
| provider.
*/

it('lists all configured language codes', function () {
    expect(Languages::codes())
        ->toContain('ur', 'ar', 'fa', 'hi', 'mr', 'ne', 'bn', 'pa', 'gu', 'ta', 'te', 'kn', 'ml', 'si', 'ru', 'el', 'am', 'he')
        ->toHaveCount(18);
});

it('resolves a known code to itself', function () {
    expect(Languages::resolve('hi'))->toBe('hi');
});

it('resolves an unknown or null code to the configured default target language', function () {
    config(['filament-auto-transliterate.target_language' => 'ur']);

    expect(Languages::resolve('bogus'))->toBe('ur')
        ->and(Languages::resolve(null))->toBe('ur');
});

it('compiles a PHP preg pattern that matches the language script and not Latin text', function () {
    $pattern = Languages::phpScriptPattern('hi');

    expect($pattern)->toBeString()
        ->and(preg_match($pattern, 'हिन्दी'))->toBe(1)
        ->and(preg_match($pattern, 'hindi'))->toBe(0);
});

it('compiles a multi-range PHP pattern for urdu covering both Unicode blocks', function () {
    $pattern = Languages::phpScriptPattern('ur');

    expect(preg_match($pattern, 'اردو'))->toBe(1) // 0600-06FF block
        ->and(preg_match($pattern, "\u{0751}"))->toBe(1); // 0750-077F block
});

it('returns null for an unknown language pattern', function () {
    expect(Languages::phpScriptPattern('xx'))->toBeNull()
        ->and(Languages::jsPattern('xx'))->toBeNull();
});

it('compiles a JS regex source of literal characters, not \u escapes', function () {
    $jsPattern = Languages::jsPattern('ur');

    expect($jsPattern)->toBeString()
        ->and($jsPattern)->toStartWith('[')
        ->and($jsPattern)->toEndWith(']')
        ->and(mb_strlen($jsPattern))->toBeGreaterThan(4) // real chars, not "؀" escapes
        ->and($jsPattern)->not->toContain('\\u');
});

it('reports rtl correctly per language', function () {
    expect(Languages::isRtl('ur'))->toBeTrue()
        ->and(Languages::isRtl('he'))->toBeTrue()
        ->and(Languages::isRtl('hi'))->toBeFalse()
        ->and(Languages::isRtl('unknown'))->toBeFalse();
});

it('builds the forJs payload shape consumed by the frontend', function () {
    $payload = Languages::forJs();

    expect($payload)->toHaveKey('ur')
        ->and($payload['ur'])->toBe([
            'label' => 'Urdu',
            'native' => 'اردو',
            'rtl' => true,
            'pattern' => Languages::jsPattern('ur'),
        ])
        ->and($payload['hi']['rtl'])->toBeFalse();

    // Every entry has exactly the documented keys.
    foreach ($payload as $entry) {
        expect(array_keys($entry))->toBe(['label', 'native', 'rtl', 'pattern']);
    }
});
