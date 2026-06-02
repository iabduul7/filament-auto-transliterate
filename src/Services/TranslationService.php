<?php

namespace Iabduul7\FilamentAutoTransliterate\Services;

use Iabduul7\FilamentAutoTransliterate\Contracts\TranslationProvider;
use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;
use Iabduul7\FilamentAutoTransliterate\Enums\TranslationMode;
use Iabduul7\FilamentAutoTransliterate\Models\TranslationCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TranslationService
{
    public function translate(string $text, ?string $targetLang = null, TranslationMode|string|null $mode = null): array
    {
        $targetLang ??= (string) config('filament-auto-transliterate.target_language', 'ur');
        $sourceLang = (string) config('filament-auto-transliterate.source_language', 'en');
        $mode = $this->resolveMode($mode);

        $this->debug("request mode={$mode->value} target={$targetLang}");

        $text = trim($text);

        // Nothing to do: empty, or already written in the target script.
        if ($text === '' || $this->isAlreadyTargetScript($text)) {
            return TranslationResult::noop($text, 'No translation needed')->toArray();
        }

        // Cache (keyed by text + target + mode so transliterate/translate of the
        // same word don't collide).
        if ($cached = $this->getCached($text, $targetLang, $mode)) {
            $this->debug("cache hit source={$cached->source}");

            return TranslationResult::success(
                translated: $cached->translated_text,
                source: $cached->source,
                confidence: (float) $cached->confidence,
                processingTime: 0,
            )->toArray();
        }

        // Run the ordered provider chain for this mode. Transliterate mode only
        // ever contains transliteration providers, so it can never silently fall
        // through to meaning-based translation — that is the core guarantee.
        foreach ($this->providersFor($mode) as $provider) {
            if (! $provider->isConfigured()) {
                continue;
            }

            $result = $this->attempt($provider, $text, $sourceLang, $targetLang);

            if ($result->success) {
                $this->cache($text, $targetLang, $mode, $result);

                return $result->toArray();
            }

            Log::warning("[FilamentAutoTransliterate] provider {$provider->key()} failed");
            $this->debug("provider {$provider->key()} error: {$result->error}");
        }

        // Everything failed. By design we leave the user's text untouched rather
        // than substituting garbage. A crude char-by-char transliteration is only
        // used if a host explicitly opts in.
        if (config('filament-auto-transliterate.fallback_transliteration', false)) {
            $fallback = $this->charFallback($text, $targetLang);
            $this->cache($text, $targetLang, $mode, $fallback);

            return $fallback->toArray();
        }

        return TranslationResult::noop($text, 'No translation available')->toArray();
    }

    /**
     * Provider availability snapshot (cached briefly), for diagnostics.
     *
     * @return array<string, array{available:bool, last_check:string, error:?string}>
     */
    public function providerStatus(): array
    {
        $status = [];

        foreach ($this->allProviderKeys() as $key) {
            $status[$key] = Cache::remember("fat_provider_health_{$key}", 300, function () use ($key) {
                $provider = $this->resolveProvider($key);
                $result = $provider && $provider->isConfigured()
                    ? $this->attempt($provider, 'test', 'en', (string) config('filament-auto-transliterate.target_language', 'ur'))
                    : TranslationResult::failure('Not configured');

                return [
                    'available' => $result->success,
                    'last_check' => now()->toISOString(),
                    'error' => $result->error,
                ];
            });
        }

        return $status;
    }

    private function resolveMode(TranslationMode|string|null $mode): TranslationMode
    {
        if ($mode instanceof TranslationMode) {
            return $mode;
        }

        if (is_string($mode) && $resolved = TranslationMode::tryFrom($mode)) {
            return $resolved;
        }

        return TranslationMode::default();
    }

    /**
     * @return list<TranslationProvider>
     */
    private function providersFor(TranslationMode $mode): array
    {
        $keys = (array) config($mode->providersConfigKey(), []);

        return array_values(array_filter(array_map(
            fn (string $key) => $this->resolveProvider($key),
            $keys,
        )));
    }

    private function resolveProvider(string $key): ?TranslationProvider
    {
        $class = config("filament-auto-transliterate.provider_map.{$key}");

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        $provider = app($class);

        return $provider instanceof TranslationProvider ? $provider : null;
    }

    /**
     * @return list<string>
     */
    private function allProviderKeys(): array
    {
        return array_keys((array) config('filament-auto-transliterate.provider_map', []));
    }

    private function attempt(TranslationProvider $provider, string $text, string $sourceLang, string $targetLang): TranslationResult
    {
        try {
            return $provider->translate($text, $sourceLang, $targetLang);
        } catch (\Throwable $e) {
            return TranslationResult::failure($e->getMessage());
        }
    }

    private function getCached(string $text, string $targetLang, TranslationMode $mode): ?TranslationCache
    {
        try {
            return TranslationCache::query()
                ->where('original_text_hash', hash('sha256', $text))
                ->where('target_language', $targetLang)
                ->where('mode', $mode->value)
                ->where('original_text', $text) // guard against hash collisions
                ->first();
        } catch (\Throwable $e) {
            Log::error('[FilamentAutoTransliterate] cache read failed: '.$e->getMessage());

            return null;
        }
    }

    private function cache(string $text, string $targetLang, TranslationMode $mode, TranslationResult $result): void
    {
        if (! $result->success || ! config('filament-auto-transliterate.cache_enabled', true)) {
            return;
        }

        try {
            // Use the model helper, which finds the row via the hash index rather
            // than matching on the unindexed `original_text` TEXT column.
            TranslationCache::cacheTranslation(
                $text,
                (string) $result->translated,
                $targetLang,
                (string) $result->source,
                $result->confidence,
                $result->processingTime,
                $mode->value,
            );
        } catch (\Throwable $e) {
            Log::error('[FilamentAutoTransliterate] cache write failed: '.$e->getMessage());
        }
    }

    private function isAlreadyTargetScript(string $text): bool
    {
        $pattern = config('filament-auto-transliterate.target_script_pattern', '/[\x{0600}-\x{06FF}]/u');

        if (! is_string($pattern) || $pattern === '') {
            return false;
        }

        return preg_match($pattern, $text) > 0;
    }

    /**
     * Crude, opt-in only, char-by-char transliteration. Disabled by default
     * because it produces phonetic nonsense; kept solely for hosts that prefer
     * "something" over leaving text unchanged.
     */
    private function charFallback(string $text, string $targetLang): TranslationResult
    {
        $startTime = microtime(true);

        $map = (array) config('filament-auto-transliterate.char_fallback_map', []);
        $lower = mb_strtolower($text);
        $out = '';

        $length = mb_strlen($lower);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($lower, $i, 1);
            $out .= $map[$char] ?? $char;
        }

        return TranslationResult::success(
            translated: $out,
            source: 'transliteration',
            confidence: 0.3,
            processingTime: round((microtime(true) - $startTime) * 1000, 2),
        );
    }

    private function debug(string $message): void
    {
        if (config('filament-auto-transliterate.log_requests', false)) {
            Log::debug("[FilamentAutoTransliterate] {$message}");
        }
    }
}
