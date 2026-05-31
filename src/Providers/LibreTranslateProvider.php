<?php

namespace Iabduul7\FilamentAutoTranslate\Providers;

use Iabduul7\FilamentAutoTranslate\Data\TranslationResult;
use Illuminate\Support\Facades\Http;

/**
 * LibreTranslate — self-hosted, open-source meaning-based translation.
 */
class LibreTranslateProvider extends AbstractProvider
{
    public function isConfigured(): bool
    {
        return (bool) $this->config('libretranslate_url');
    }

    public function key(): string
    {
        return 'libretranslate';
    }

    public function translate(string $text, string $sourceLang, string $targetLang): TranslationResult
    {
        $startTime = microtime(true);

        $endpoint = rtrim((string) $this->config('libretranslate_url', 'http://localhost:5000'), '/');

        $response = Http::timeout($this->timeout())->post("{$endpoint}/translate", [
            'q' => $text,
            'source' => $sourceLang,
            'target' => $targetLang,
            'format' => 'text',
            'api_key' => (string) $this->config('libretranslate_key', ''),
        ]);

        if ($response->successful()) {
            $data = $response->json();

            if (! empty($data['translatedText'])) {
                return TranslationResult::success(
                    translated: $data['translatedText'],
                    source: $this->key(),
                    confidence: 0.85,
                    processingTime: $this->elapsed($startTime),
                );
            }
        }

        return TranslationResult::failure('LibreTranslate API failed');
    }
}
