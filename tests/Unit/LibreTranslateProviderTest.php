<?php

use Iabduul7\FilamentAutoTransliterate\Providers\LibreTranslateProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'filament-auto-transliterate.libretranslate_url' => 'https://libre.test/',
        'filament-auto-transliterate.libretranslate_key' => null,
    ]);
});

function libre(): LibreTranslateProvider
{
    return app(LibreTranslateProvider::class);
}

it('is configured only with a url', function () {
    config(['filament-auto-transliterate.libretranslate_url' => null]);
    expect(libre()->isConfigured())->toBeFalse();

    config(['filament-auto-transliterate.libretranslate_url' => 'https://libre.test']);
    expect(libre()->isConfigured())->toBeTrue()
        ->and(libre()->key())->toBe('libretranslate');
});

it('posts to /translate and only sends api_key when configured', function () {
    Http::fake(['libre.test/*' => Http::response(['translatedText' => 'مدد'])]);

    libre()->translate('help', 'en', 'ur');

    Http::assertSent(fn ($request) => $request->url() === 'https://libre.test/translate'
        && $request->data()['q'] === 'help'
        && $request->data()['source'] === 'en'
        && $request->data()['target'] === 'ur'
        && ! array_key_exists('api_key', $request->data()));

    config(['filament-auto-transliterate.libretranslate_key' => 'k']);
    libre()->translate('help', 'en', 'ur');

    Http::assertSent(fn ($request) => ($request->data()['api_key'] ?? null) === 'k');
});

it('parses a successful response', function () {
    Http::fake(['*' => Http::response(['translatedText' => 'مدد'])]);

    $result = libre()->translate('help', 'en', 'ur');

    expect($result->success)->toBeTrue()
        ->and($result->translated)->toBe('مدد')
        ->and($result->source)->toBe('libretranslate');
});

it('treats HTTP 500 and 429 as errors', function () {
    Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);
    expect(libre()->translate('help', 'en', 'ur')->isError())->toBeTrue();

    Http::fake(['*' => Http::response(['error' => 'rate'], 429)]);
    expect(libre()->translate('help', 'en', 'ur')->isError())->toBeTrue();
});

it('treats a transport failure as an error', function () {
    Http::fake(['*' => Http::failedConnection()]);

    expect(libre()->translate('help', 'en', 'ur')->isError())->toBeTrue();
});

it('treats malformed JSON as an error', function () {
    Http::fake(['*' => Http::response('<html></html>', 200)]);
    expect(libre()->translate('help', 'en', 'ur')->isError())->toBeTrue();

    Http::fake(['*' => Http::response(['nope' => 1])]);
    expect(libre()->translate('help', 'en', 'ur')->isError())->toBeTrue();
});
