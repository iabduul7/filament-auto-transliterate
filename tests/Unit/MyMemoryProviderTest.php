<?php

use Iabduul7\FilamentAutoTransliterate\Providers\MyMemoryProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['filament-auto-transliterate.mymemory_email' => null]);
});

function myMemory(): MyMemoryProvider
{
    return app(MyMemoryProvider::class);
}

it('is always configured (works anonymously)', function () {
    expect(myMemory()->isConfigured())->toBeTrue()
        ->and(myMemory()->key())->toBe('mymemory');
});

it('sends the langpair and optional email, with total and connect timeouts', function () {
    config(['filament-auto-transliterate.mymemory_email' => 'me@example.com']);
    Http::fake(['api.mymemory.translated.net/*' => Http::response([
        'responseStatus' => 200,
        'responseData' => ['translatedText' => 'مدد', 'match' => 0.9],
    ])]);

    myMemory()->translate('help', 'en', 'ur');

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://api.mymemory.translated.net/get')
            && $query['q'] === 'help'
            && $query['langpair'] === 'en|ur'
            && $query['de'] === 'me@example.com';
    });

    $http = new ReflectionMethod(MyMemoryProvider::class, 'http');
    $options = (new ReflectionProperty(PendingRequest::class, 'options'))
        ->getValue($http->invoke(myMemory()));

    expect($options['connect_timeout'])->toBe(3)
        ->and($options['timeout'])->toBe(5);
});

it('parses a successful response', function () {
    Http::fake(['api.mymemory.translated.net/*' => Http::response([
        'responseStatus' => 200,
        'responseData' => ['translatedText' => 'مدد', 'match' => 0.9],
    ])]);

    $result = myMemory()->translate('help', 'en', 'ur');

    expect($result->success)->toBeTrue()
        ->and($result->translated)->toBe('مدد')
        ->and($result->source)->toBe('mymemory')
        ->and($result->confidence)->toBe(0.9);
});

it('treats HTTP 500 as an error', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $result = myMemory()->translate('help', 'en', 'ur');

    expect($result->success)->toBeFalse()->and($result->isError())->toBeTrue();
});

it('treats HTTP 429 as an error so the breaker trips', function () {
    Http::fake(['*' => Http::response('slow down', 429)]);

    expect(myMemory()->translate('help', 'en', 'ur')->isError())->toBeTrue();
});

it('treats a body-level 429 as an error', function () {
    Http::fake(['*' => Http::response(['responseStatus' => 429, 'responseData' => ['translatedText' => '']])]);

    expect(myMemory()->translate('help', 'en', 'ur')->isError())->toBeTrue();
});

it('treats a transport failure as an error, not an exception', function () {
    Http::fake(['*' => Http::failedConnection()]);

    $result = myMemory()->translate('help', 'en', 'ur');

    expect($result->isError())->toBeTrue()
        ->and($result->error)->not->toContain('help');
});

it('treats malformed JSON as an error', function () {
    Http::fake(['*' => Http::response('<html>not json</html>', 200)]);

    expect(myMemory()->translate('help', 'en', 'ur')->isError())->toBeTrue();
});

it('treats the quota warning as a miss, not an error', function () {
    Http::fake(['*' => Http::response([
        'responseStatus' => 200,
        'responseData' => ['translatedText' => 'MYMEMORY WARNING: YOU USED ALL AVAILABLE FREE TRANSLATIONS FOR TODAY.'],
    ])]);

    $result = myMemory()->translate('help', 'en', 'ur');

    expect($result->success)->toBeFalse()
        ->and($result->isMiss())->toBeTrue()
        ->and($result->isError())->toBeFalse();
});

it('treats an echo of the input as a miss', function () {
    Http::fake(['*' => Http::response([
        'responseStatus' => 200,
        'responseData' => ['translatedText' => 'Help', 'match' => 0.5],
    ])]);

    $result = myMemory()->translate('help', 'en', 'ur');

    expect($result->isMiss())->toBeTrue();
});
