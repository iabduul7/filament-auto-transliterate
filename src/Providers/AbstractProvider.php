<?php

namespace Iabduul7\FilamentAutoTransliterate\Providers;

use Iabduul7\FilamentAutoTransliterate\Contracts\TranslationProvider;
use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class AbstractProvider implements TranslationProvider
{
    /** Seconds allowed to establish the TCP/TLS connection. */
    protected const CONNECT_TIMEOUT = 3;

    public function isConfigured(): bool
    {
        return true;
    }

    protected function timeout(): int
    {
        return (int) config('filament-auto-transliterate.api_timeout', 5);
    }

    /**
     * A pending HTTP request with the package's total + connect timeouts.
     */
    protected function http(): PendingRequest
    {
        return Http::timeout($this->timeout())->connectTimeout(self::CONNECT_TIMEOUT);
    }

    /**
     * Run a provider attempt, converting transport exceptions into an ERROR
     * result (so the provider honours the "never throw" contract). Only the
     * exception class is kept: the message embeds the request URL, which can
     * contain user text or an API key.
     *
     * @param  callable(): TranslationResult  $attempt
     */
    protected function guard(callable $attempt): TranslationResult
    {
        try {
            return $attempt();
        } catch (ConnectionException|RequestException $e) {
            return TranslationResult::failure('Transport error: '.class_basename($e));
        }
    }

    /**
     * An ERROR for any non-2xx response (401/403 bad credentials, 429 rate
     * limit, 5xx outage...), or null when the response is usable. All of these
     * are provider problems, not "no result for this text".
     */
    protected function httpError(Response $response, string $name): ?TranslationResult
    {
        if ($response->successful()) {
            return null;
        }

        return TranslationResult::failure("{$name} HTTP {$response->status()}");
    }

    /**
     * Elapsed milliseconds since the given start time, rounded to 2 decimals.
     */
    protected function elapsed(float $startTime): float
    {
        return round((microtime(true) - $startTime) * 1000, 2);
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config("filament-auto-transliterate.{$key}", $default);
    }
}
