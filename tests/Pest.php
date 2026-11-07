<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Iabduul7\FilamentAutoTransliterate\Tests\TestCase;
use Illuminate\Http\Client\Request;

uses(TestCase::class)->in(__DIR__);

/**
 * Http::fake() stub that simulates a transport-level failure (DNS/connect error).
 *
 * Equivalent to Http::failedConnection(), which only exists in newer Laravel 11.x
 * releases — inlined so the prefer-lowest CI job (laravel/framework 11.0) passes.
 */
function fakeFailedConnection(): Closure
{
    return fn (Request $request) => Create::rejectionFor(new ConnectException(
        "cURL error 6: Could not resolve host: {$request->toPsrRequest()->getUri()->getHost()}",
        $request->toPsrRequest(),
    ));
}
