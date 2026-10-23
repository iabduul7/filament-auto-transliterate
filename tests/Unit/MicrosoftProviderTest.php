<?php

use Iabduul7\FilamentAutoTransliterate\Providers\MicrosoftProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'filament-auto-transliterate.microsoft_key' => 'ms-key',
        'filament-auto-transliterate.microsoft_endpoint' => 'https://ms.test/',
        'filament-auto-transliterate.microsoft_region' => null,
    ]);
});

function microsoft(): MicrosoftProvider
{
    return app(MicrosoftProvider::class);
}

function microsoftOk(): array
{
    return [['translations' => [['text' => 'مدد', 'to' => 'ur']]]];
}

it('is configured only with both a key and an endpoint', function () {
    expect(microsoft()->isConfigured())->toBeTrue()
        ->and(microsoft()->key())->toBe('microsoft');

    config(['filament-auto-transliterate.microsoft_key' => null]);
    expect(microsoft()->isConfigured())->toBeFalse();

    config(['filament-auto-transliterate.microsoft_key' => 'k', 'filament-auto-transliterate.microsoft_endpoint' => null]);
    expect(microsoft()->isConfigured())->toBeFalse();
});

it('sends the key header, request url and body, and no region header by default', function () {
    Http::fake(['ms.test/*' => Http::response(microsoftOk())]);

    microsoft()->translate('help', 'en', 'ur');

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://ms.test/translate?')
            && $query === ['api-version' => '3.0', 'from' => 'en', 'to' => 'ur']
            && $request->header('Ocp-Apim-Subscription-Key') === ['ms-key']
            && ! $request->hasHeader('Ocp-Apim-Subscription-Region')
            && $request->data() === [['text' => 'help']];
    });
});

it('sends the region header only when microsoft_region is set', function () {
    config(['filament-auto-transliterate.microsoft_region' => 'westeurope']);
    Http::fake(['ms.test/*' => Http::response(microsoftOk())]);

    microsoft()->translate('help', 'en', 'ur');

    Http::assertSent(fn ($request) => $request->header('Ocp-Apim-Subscription-Region') === ['westeurope']);
});

it('parses a successful response', function () {
    Http::fake(['*' => Http::response(microsoftOk())]);

    $result = microsoft()->translate('help', 'en', 'ur');

    expect($result->success)->toBeTrue()
        ->and($result->translated)->toBe('مدد')
        ->and($result->source)->toBe('microsoft');
});

it('treats HTTP 500, 429 and 401 as errors', function () {
    foreach ([500, 429, 401] as $status) {
        Http::fake(['*' => Http::response(['error' => ['code' => $status]], $status)]);

        expect(microsoft()->translate('help', 'en', 'ur')->isError())->toBeTrue();
    }
});

it('treats a transport failure as an error', function () {
    Http::fake(['*' => Http::failedConnection()]);

    expect(microsoft()->translate('help', 'en', 'ur')->isError())->toBeTrue();
});

it('treats malformed JSON as an error', function () {
    Http::fake(['*' => Http::response('<html></html>', 200)]);
    expect(microsoft()->translate('help', 'en', 'ur')->isError())->toBeTrue();

    Http::fake(['*' => Http::response([['translations' => []]])]);
    expect(microsoft()->translate('help', 'en', 'ur')->isError())->toBeTrue();
});
