<?php

namespace Iabduul7\FilamentAutoTransliterate\Providers;

use Iabduul7\FilamentAutoTransliterate\Contracts\TranslationProvider;

abstract class AbstractProvider implements TranslationProvider
{
    public function isConfigured(): bool
    {
        return true;
    }

    protected function timeout(): int
    {
        return (int) config('filament-auto-transliterate.api_timeout', 5);
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
