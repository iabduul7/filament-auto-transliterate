<?php

namespace Iabduul7\FilamentAutoTransliterate\Services;

use Iabduul7\FilamentAutoTransliterate\Contracts\TranslationProvider;
use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;
use Iabduul7\FilamentAutoTransliterate\Enums\TranslationMode;
use Iabduul7\FilamentAutoTransliterate\Models\TranslationCache;
use Iabduul7\FilamentAutoTransliterate\Support\Languages;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TranslationService
{
    public function translate(string $text, ?string $targetLang = null, TranslationMode|string|null $mode = null): array
    {
        // Defense-in-depth: the service is also a public API for host apps, so
        // an unknown/garbage target_lang must never reach a provider.
        $targetLang = Languages::resolve($targetLang);
        $sourceLang = (string) config('filament-auto-transliterate.source_language', 'en');
        $mode = $this->resolveMode($mode);

        $this->debug("request mode={$mode->value} target={$targetLang}");

        $text = trim($text);

        // Nothing to do: empty, or already written in the target script.
        if ($text === '' || $this->isAlreadyTargetScript($text, $targetLang)) {
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

            // Free endpoints fail in bursts; skip a provider that has recently
            // failed repeatedly rather than eating another timeout per word.
            if ($this->isCircuitOpen($provider)) {
                $this->debug("provider {$provider->key()} skipped: circuit breaker open");

                continue;
            }

            $result = $this->attempt($provider, $text, $sourceLang, $targetLang);

            if ($result->success) {
                $this->recordProviderSuccess($provider);
                $this->cache($text, $targetLang, $mode, $result);

                return $result->toArray();
            }

            $this->recordProviderFailure($provider);

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
     * Store a user's correction of an applied word as ground-truth cache row
     * (source=user_correction, confidence=0.99) so it outranks provider output
     * forever after. See docs/02-self-improvement.md. Thin, testable wrapper —
     * the controller is a validator + JSON shell around this.
     *
     * @return array{success:bool, target_lang?:string, mode?:string, message?:string}
     */
    public function learn(string $original, string $corrected, ?string $targetLang = null, TranslationMode|string|null $mode = null): array
    {
        $targetLang = Languages::resolve($targetLang);
        $mode = $this->resolveMode($mode);
        $original = trim($original);
        $corrected = trim($corrected);

        try {
            TranslationCache::cacheTranslation(
                $original,
                $corrected,
                $targetLang,
                'user_correction',
                0.99,
                0.0,
                $mode->value,
            );

            $this->debug("learned correction target={$targetLang} mode={$mode->value}");

            return [
                'success' => true,
                'target_lang' => $targetLang,
                'mode' => $mode->value,
            ];
        } catch (\Throwable $e) {
            Log::error('[FilamentAutoTransliterate] learn write failed: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to store correction',
            ];
        }
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

    /**
     * Per-language script detection (docs/01-multi-language.md). An explicit
     * `target_script_pattern` config value wins as a global override for
     * backwards compat; otherwise the pattern is compiled from the resolved
     * language's `script_ranges`.
     */
    private function isAlreadyTargetScript(string $text, string $targetLang): bool
    {
        $override = config('filament-auto-transliterate.target_script_pattern');

        $pattern = is_string($override) && $override !== ''
            ? $override
            : Languages::phpScriptPattern($targetLang);

        if (! is_string($pattern) || $pattern === '') {
            return false;
        }

        return preg_match($pattern, $text) > 0;
    }

    /**
     * True while the provider's circuit breaker is open (recently failed
     * `provider_failure_threshold` times in a row) — the provider is skipped
     * without another attempt until `provider_failure_cooldown` elapses.
     */
    private function isCircuitOpen(TranslationProvider $provider): bool
    {
        $state = Cache::get($this->circuitBreakerKey($provider->key()));

        return is_array($state) && ($state['open_until'] ?? 0) > time();
    }

    private function recordProviderFailure(TranslationProvider $provider): void
    {
        $key = $provider->key();
        $cacheKey = $this->circuitBreakerKey($key);
        $threshold = (int) config('filament-auto-transliterate.provider_failure_threshold', 3);
        $cooldown = (int) config('filament-auto-transliterate.provider_failure_cooldown', 120);

        $state = Cache::get($cacheKey, ['failures' => 0, 'open_until' => 0]);
        $wasOpen = ($state['open_until'] ?? 0) > time();
        $state['failures'] = ($state['failures'] ?? 0) + 1;

        if (! $wasOpen && $state['failures'] >= $threshold) {
            $state['open_until'] = time() + $cooldown;
            $state['failures'] = 0;

            // One warning per breaker trip, not one per skipped request
            // afterwards (those are silent, see isCircuitOpen()).
            Log::warning("[FilamentAutoTransliterate] circuit breaker opened for provider {$key} after {$threshold} consecutive failures");
        }

        Cache::put($cacheKey, $state, $cooldown + 60);
    }

    private function recordProviderSuccess(TranslationProvider $provider): void
    {
        Cache::forget($this->circuitBreakerKey($provider->key()));
    }

    private function circuitBreakerKey(string $key): string
    {
        return "fat_provider_cb_{$key}";
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
