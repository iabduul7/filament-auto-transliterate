<?php

use Iabduul7\FilamentAutoTransliterate\Services\TranslationService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function service(): TranslationService
{
    return app(TranslationService::class);
}

it('leaves text untouched when it is already in the target script', function () {
    $result = service()->translate('یہ', 'ur');

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('No translation needed')
        ->and($result['original'])->toBe('یہ');
});

it('transliterates via google input tools in transliterate mode', function () {
    Http::fake([
        'inputtools.google.com/*' => Http::response(['SUCCESS', [['yeh', ['یہ']]]]),
    ]);

    $result = service()->translate('yeh', 'ur', 'transliterate');

    expect($result['success'])->toBeTrue()
        ->and($result['source'])->toBe('google_input_tools')
        ->and($result['translated'])->toBe('یہ');
});

it('never falls through to a meaning provider when transliteration misses', function () {
    Http::fake([
        'inputtools.google.com/*' => Http::response('error', 500),
        '*' => Http::response(['responseStatus' => 200, 'responseData' => ['translatedText' => 'LEAK']]),
    ]);

    $result = service()->translate('zzqq', 'ur', 'transliterate');

    // The whole point of the package: a transliterate miss leaves the text
    // unchanged and must NOT reach a translation provider.
    expect($result['success'])->toBeFalse()
        ->and($result['original'])->toBe('zzqq');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'mymemory'));
});

it('translates by meaning via mymemory in translate mode', function () {
    Http::fake([
        'api.mymemory.translated.net/*' => Http::response([
            'responseStatus' => 200,
            'responseData' => ['translatedText' => 'مدد', 'match' => 0.9],
        ]),
    ]);

    $result = service()->translate('help', 'ur', 'translate');

    expect($result['success'])->toBeTrue()
        ->and($result['source'])->toBe('mymemory')
        ->and($result['translated'])->toBe('مدد');
});

it('serves a cached result without re-hitting providers', function () {
    Http::fake([
        'inputtools.google.com/*' => Http::response(['SUCCESS', [['yeh', ['یہ']]]]),
    ]);

    service()->translate('yeh', 'ur', 'transliterate');
    $afterFirst = count(Http::recorded());

    $second = service()->translate('yeh', 'ur', 'transliterate');

    expect(count(Http::recorded()))->toBe($afterFirst)
        ->and($second['success'])->toBeTrue()
        ->and($second['source'])->toBe('google_input_tools');
});

it('caches transliterate and translate of the same word separately', function () {
    Http::fake([
        'inputtools.google.com/*' => Http::response(['SUCCESS', [['school', ['سکول']]]]),
        'api.mymemory.translated.net/*' => Http::response([
            'responseStatus' => 200,
            'responseData' => ['translatedText' => 'مدرسہ', 'match' => 0.9],
        ]),
    ]);

    $translit = service()->translate('school', 'ur', 'transliterate');
    $translate = service()->translate('school', 'ur', 'translate');

    expect($translit['translated'])->toBe('سکول')
        ->and($translate['translated'])->toBe('مدرسہ');
});

it('does not transliterate on total failure when the char fallback is disabled', function () {
    config(['filament-auto-transliterate.fallback_transliteration' => false]);
    Http::fake(['*' => Http::response('error', 500)]);

    $result = service()->translate('zzqq', 'ur', 'translate');

    expect($result['success'])->toBeFalse()
        ->and($result)->not->toHaveKey('translated_text');
    expect($result['translated'])->toBeNull();
});

it('uses the char fallback only when explicitly enabled', function () {
    config(['filament-auto-transliterate.fallback_transliteration' => true]);
    Http::fake(['*' => Http::response('error', 500)]);

    $result = service()->translate('zzqq', 'ur', 'translate');

    expect($result['success'])->toBeTrue()
        ->and($result['source'])->toBe('transliteration');
});

it('falls back to the configured default target for an unknown language code', function () {
    config(['filament-auto-transliterate.target_language' => 'ur']);
    Http::fake([
        'inputtools.google.com/*' => Http::response(['SUCCESS', [['yeh', ['یہ']]]]),
    ]);

    service()->translate('yeh', 'not-a-real-language', 'transliterate');

    // Defense-in-depth: Languages::resolve() maps the garbage code to the
    // configured default before it ever reaches a provider.
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'itc=ur-t-i0-und'));
});

it('detects Devanagari input as already in the target script for Hindi', function () {
    $result = service()->translate('हिन्दी', 'hi', 'transliterate');

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('No translation needed');

    Http::assertNothingSent();
});

it('opens the circuit breaker after consecutive failures and skips the provider until it resets', function () {
    config([
        'filament-auto-transliterate.provider_failure_threshold' => 3,
        'filament-auto-transliterate.provider_failure_cooldown' => 120,
        // Isolate the transliterate chain to just the network provider so we
        // can count its hits precisely.
        'filament-auto-transliterate.providers.transliterate' => ['google_input_tools'],
    ]);
    Http::fake(['inputtools.google.com/*' => Http::response('error', 500)]);

    // Three distinct words so the cache never short-circuits the attempt.
    service()->translate('wordone', 'ur', 'transliterate');
    service()->translate('wordtwo', 'ur', 'transliterate');
    service()->translate('wordthree', 'ur', 'transliterate');

    expect(Http::recorded())->toHaveCount(3);

    // The breaker is now open: a fourth distinct word must not hit the network.
    service()->translate('wordfour', 'ur', 'transliterate');

    expect(Http::recorded())->toHaveCount(3);
});

it('passes alternatives through the response array', function () {
    Http::fake([
        'inputtools.google.com/*' => Http::response([
            'SUCCESS',
            [['yeh', ['یہ', 'یے', 'یہہ']]],
        ]),
    ]);

    $result = service()->translate('yeh', 'ur', 'transliterate');

    expect($result['success'])->toBeTrue()
        ->and($result['translated'])->toBe('یہ')
        ->and($result['alternatives'])->toBe(['یے', 'یہہ']);
});
