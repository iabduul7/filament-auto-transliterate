<?php

namespace Iabduul7\FilamentAutoTransliterate\Providers;

use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;

/**
 * Local JSON dictionary — an offline, zero-latency glossary checked before any
 * network provider. Maps known source words to fixed target words (e.g. domain
 * terms). Only returns a hit when EVERY word of a short phrase is found, so it
 * never partially mangles input.
 *
 * The dictionary path may contain a `{target}` placeholder so a host can ship
 * one file per target language, e.g. `.../dictionaries/en-{target}.json`. A
 * target language without its own file is a MISS (not an error), so a missing
 * per-language file can never trip the circuit breaker.
 */
class DictionaryProvider extends AbstractProvider
{
    /** @var array<string, array<string, string>|null> cache keyed by resolved path; null = unreadable */
    private array $loaded = [];

    public function isConfigured(): bool
    {
        $path = $this->config($this->pathConfigKey());

        if (! is_string($path) || $path === '') {
            return false;
        }

        if (str_contains($path, '{target}')) {
            // Per-language files: configured as soon as the directory exists.
            // Whether a given language has a file is decided per request (a
            // missing one is a miss), not here — otherwise availability would
            // hinge on the default target's file only.
            $default = (string) config('filament-auto-transliterate.target_language', 'ur');

            return is_dir(dirname(str_replace('{target}', $default, $path)));
        }

        return file_exists($path);
    }

    /**
     * The config key holding this dictionary's file path. Overridden by
     * subclasses (e.g. TransliterationDictionaryProvider) that read a
     * separate file so a meaning-glossary can never answer a phonetic query.
     */
    protected function pathConfigKey(): string
    {
        return 'dictionary_path';
    }

    public function key(): string
    {
        return 'dictionary';
    }

    public function translate(string $text, string $sourceLang, string $targetLang): TranslationResult
    {
        $startTime = microtime(true);

        $path = $this->resolvePath($targetLang);

        if ($path === null) {
            return TranslationResult::miss('No dictionary for this target language');
        }

        $dictionary = $this->dictionary($path);

        if ($dictionary === null) {
            // A file that exists but is not a valid JSON object is a host
            // misconfiguration, not a "word not found".
            return TranslationResult::failure('Dictionary file is unreadable or not valid JSON');
        }

        if ($dictionary === []) {
            return TranslationResult::miss('Dictionary empty');
        }

        $words = preg_split('/\s+/u', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $maxWords = (int) $this->config('dictionary_max_words', 3);

        if ($words === []) {
            return TranslationResult::miss('Nothing to look up');
        }

        if (count($words) > $maxWords) {
            return TranslationResult::miss('Phrase too long for dictionary');
        }

        $translatedWords = [];
        foreach ($words as $word) {
            $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
            if ($clean === '' || ! isset($dictionary[$clean])) {
                return TranslationResult::miss('Not found in dictionary');
            }
            $translatedWords[] = $dictionary[$clean];
        }

        return TranslationResult::success(
            translated: implode(' ', $translatedWords),
            source: $this->key(),
            confidence: 0.95,
            processingTime: $this->elapsed($startTime),
        );
    }

    /**
     * @return array<string, string>|null null when the file cannot be parsed
     */
    private function dictionary(string $path): ?array
    {
        if (! array_key_exists($path, $this->loaded)) {
            $decoded = json_decode((string) @file_get_contents($path), true);

            if (! is_array($decoded)) {
                $this->loaded[$path] = null;
            } else {
                // Normalise keys to lowercase (multibyte-aware) so lookups are
                // case-insensitive.
                $normalised = [];
                foreach ($decoded as $key => $value) {
                    if (is_string($value)) {
                        $normalised[mb_strtolower((string) $key)] = $value;
                    }
                }
                $this->loaded[$path] = $normalised;
            }
        }

        return $this->loaded[$path];
    }

    private function resolvePath(string $targetLang): ?string
    {
        $path = $this->config($this->pathConfigKey());

        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = str_replace('{target}', $targetLang, $path);

        return file_exists($path) ? $path : null;
    }
}
