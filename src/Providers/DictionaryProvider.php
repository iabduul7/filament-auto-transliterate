<?php

namespace Iabduul7\FilamentAutoTranslate\Providers;

use Iabduul7\FilamentAutoTranslate\Data\TranslationResult;

/**
 * Local JSON dictionary — an offline, zero-latency glossary checked before any
 * network provider. Maps known source words to fixed target words (e.g. domain
 * terms). Only returns a hit when EVERY word of a short phrase is found, so it
 * never partially mangles input.
 *
 * The dictionary path may contain a `{target}` placeholder so a host can ship
 * one file per target language, e.g. `.../dictionaries/en-{target}.json`.
 */
class DictionaryProvider extends AbstractProvider
{
    /** @var array<string, array<string, string>> cache keyed by resolved path */
    private array $loaded = [];

    public function isConfigured(): bool
    {
        return $this->resolvePath(config('filament-auto-translate.target_language', 'ur')) !== null;
    }

    public function key(): string
    {
        return 'dictionary';
    }

    public function translate(string $text, string $sourceLang, string $targetLang): TranslationResult
    {
        $startTime = microtime(true);

        $dictionary = $this->dictionary($targetLang);

        if ($dictionary === []) {
            return TranslationResult::failure('Dictionary empty or missing');
        }

        $words = explode(' ', strtolower(trim($text)));
        $maxWords = (int) $this->config('dictionary_max_words', 3);

        if (count($words) > $maxWords) {
            return TranslationResult::failure('Phrase too long for dictionary');
        }

        $translatedWords = [];
        foreach ($words as $word) {
            $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
            if ($clean === '' || ! isset($dictionary[$clean])) {
                return TranslationResult::failure('Not found in dictionary');
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
     * @return array<string, string>
     */
    private function dictionary(string $targetLang): array
    {
        $path = $this->resolvePath($targetLang);

        if ($path === null) {
            return [];
        }

        if (! isset($this->loaded[$path])) {
            $decoded = json_decode((string) file_get_contents($path), true);
            // Normalise keys to lowercase so lookups are case-insensitive.
            $this->loaded[$path] = is_array($decoded)
                ? array_change_key_case($decoded, CASE_LOWER)
                : [];
        }

        return $this->loaded[$path];
    }

    private function resolvePath(string $targetLang): ?string
    {
        $path = $this->config('dictionary_path');

        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = str_replace('{target}', $targetLang, $path);

        return file_exists($path) ? $path : null;
    }
}
