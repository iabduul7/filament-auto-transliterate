<?php

use Iabduul7\FilamentAutoTransliterate\Providers\GoogleTranslateProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function googleTranslate(): GoogleTranslateProvider
{
    return app(GoogleTranslateProvider::class);
}

it('is configured only with an api key', function () {
    config(['filament-auto-transliterate.google_api_key' => null]);
    expect(googleTranslate()->isConfigured())->toBeFalse();

    config(['filament-auto-transliterate.google_api_key' => 'abc']);
    expect(googleTranslate()->isConfigured())->toBeTrue()
        ->and(googleTranslate()->key())->toBe('google');
});

it('sends the api key as a query parameter, not in the body', function () {
    config(['filament-auto-transliterate.google_api_key' => 'secret-key']);
    Http::fake(['translation.googleapis.com/*' => Http::response([
        'data' => ['translations' => [['translatedText' => 'مدد']]],
    ])]);

    googleTranslate()->translate('help', 'en', 'ur');

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://translation.googleapis.com/language/translate/v2?')
            && $query['key'] === 'secret-key'
            && ! array_key_exists('key', $request->data())
            && $request->data()['q'] === 'help'
            && $request->data()['source'] === 'en'
            && $request->data()['target'] === 'ur';
    });
});

it('parses a successful response', function () {
    config(['filament-auto-transliterate.google_api_key' => 'abc']);
    Http::fake(['*' => Http::response([
        'data' => ['translations' => [['translatedText' => 'مدد', 'confidence' => 0.7]]],
    ])]);

    $result = googleTranslate()->translate('help', 'en', 'ur');

    expect($result->success)->toBeTrue()
        ->and($result->translated)->toBe('مدد')
        ->and($result->source)->toBe('google')
        ->and($result->confidence)->toBe(0.7);
});

it('treats HTTP 500 as an error', function () {
    config(['filament-auto-transliterate.google_api_key' => 'abc']);
    Http::fake(['*' => Http::response('boom', 500)]);

    expect(googleTranslate()->translate('help', 'en', 'ur')->isError())->toBeTrue();
});

it('treats HTTP 429 and 401 as errors', function () {
    config(['filament-auto-transliterate.google_api_key' => 'abc']);

    Http::fake(['*' => Http::response('', 429)]);
    expect(googleTranslate()->translate('help', 'en', 'ur')->isError())->toBeTrue();

    Http::fake(['*' => Http::response('', 401)]);
    expect(googleTranslate()->translate('help', 'en', 'ur')->isError())->toBeTrue();
});

it('treats a transport failure as an error and never leaks the key', function () {
    config(['filament-auto-transliterate.google_api_key' => 'secret-key']);
    Http::fake(['*' => Http::failedConnection()]);

    $result = googleTranslate()->translate('help', 'en', 'ur');

    expect($result->isError())->toBeTrue()
        ->and($result->error)->not->toContain('secret-key');
});

it('treats malformed JSON as an error', function () {
    config(['filament-auto-transliterate.google_api_key' => 'abc']);

    Http::fake(['*' => Http::response('<html></html>', 200)]);
    expect(googleTranslate()->translate('help', 'en', 'ur')->isError())->toBeTrue();

    Http::fake(['*' => Http::response(['data' => ['unexpected' => true]])]);
    expect(googleTranslate()->translate('help', 'en', 'ur')->isError())->toBeTrue();
});
