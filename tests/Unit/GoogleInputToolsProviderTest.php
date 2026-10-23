<?php

use Iabduul7\FilamentAutoTransliterate\Providers\GoogleInputToolsProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function inputTools(): GoogleInputToolsProvider
{
    return app(GoogleInputToolsProvider::class);
}

it('needs no credentials and is always configured', function () {
    expect(inputTools()->isConfigured())->toBeTrue()
        ->and(inputTools()->key())->toBe('google_input_tools');
});

it('requests the language-specific input method with the configured candidate count', function () {
    config(['filament-auto-transliterate.suggestions_per_word' => 4]);
    Http::fake(['inputtools.google.com/*' => Http::response(['SUCCESS', [['yeh', ['یہ']]]])]);

    inputTools()->translate('yeh', 'en', 'ur');

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://inputtools.google.com/request?')
            && $query['text'] === 'yeh'
            && $query['itc'] === 'ur-t-i0-und'
            && $query['num'] === '4';
    });
});

it('parses a successful response and exposes alternatives', function () {
    Http::fake(['*' => Http::response(['SUCCESS', [['yeh', ['یہ', 'یے']]]])]);

    $result = inputTools()->translate('yeh', 'en', 'ur');

    expect($result->success)->toBeTrue()
        ->and($result->translated)->toBe('یہ')
        ->and($result->alternatives)->toBe(['یے'])
        ->and($result->source)->toBe('google_input_tools');
});

it('keeps the source token for a segment with no candidates', function () {
    Http::fake(['*' => Http::response(['SUCCESS', [['yeh', ['یہ']], ['xyzzy', []], ['aaj', ['آج']]]])]);

    $result = inputTools()->translate('yeh xyzzy aaj', 'en', 'ur');

    expect($result->success)->toBeTrue()
        ->and($result->translated)->toBe('یہ xyzzy آج')
        ->and($result->confidence)->toBeLessThan(0.95);
});

it('treats a response with no candidates at all as a miss', function () {
    Http::fake(['*' => Http::response(['SUCCESS', [['xyzzy', []]]])]);

    $result = inputTools()->translate('xyzzy', 'en', 'ur');

    expect($result->success)->toBeFalse()
        ->and($result->isMiss())->toBeTrue()
        ->and($result->isError())->toBeFalse();
});

it('treats HTTP 500 and 429 as errors', function () {
    Http::fake(['*' => Http::response('boom', 500)]);
    expect(inputTools()->translate('yeh', 'en', 'ur')->isError())->toBeTrue();

    Http::fake(['*' => Http::response('slow down', 429)]);
    expect(inputTools()->translate('yeh', 'en', 'ur')->isError())->toBeTrue();
});

it('treats a transport failure as an error', function () {
    Http::fake(['*' => Http::failedConnection()]);

    $result = inputTools()->translate('yeh', 'en', 'ur');

    expect($result->isError())->toBeTrue()
        ->and($result->error)->not->toContain('yeh');
});

it('treats malformed or unexpected JSON as an error', function () {
    foreach ([
        Http::response('<html>nope</html>', 200),
        Http::response(['FAILED_TO_PARSE_REQUEST_BODY']),
        Http::response(['SUCCESS']),
        Http::response(['SUCCESS', 'not-an-array']),
        Http::response(['a' => 'b']),
    ] as $response) {
        Http::fake(['*' => $response]);

        expect(inputTools()->translate('yeh', 'en', 'ur')->isError())->toBeTrue();
    }
});

it('does not throw on odd segment shapes', function () {
    Http::fake(['*' => Http::response(['SUCCESS', ['junk', ['yeh', ['یہ']], [null, 5]]])]);

    $result = inputTools()->translate('yeh', 'en', 'ur');

    expect($result->success)->toBeTrue()->and($result->translated)->toBe('یہ');
});
