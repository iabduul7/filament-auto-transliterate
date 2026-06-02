<?php

namespace Iabduul7\FilamentAutoTransliterate\Providers;

use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;
use Illuminate\Support\Facades\Http;

/**
 * Google Cloud Translation v2 — paid, meaning-based translation.
 */
class GoogleTranslateProvider extends AbstractProvider
{
    public function isConfigured(): bool
    {
        return (bool) $this->config('google_api_key');
    }

    public function key(): string
    {
        return 'google';
    }

    public function translate(string $text, string $sourceLang, string $targetLang): TranslationResult
    {
        $startTime = microtime(true);

        $response = Http::timeout($this->timeout())->post('https://translation.googleapis.com/language/translate/v2', [
            'key' => (string) $this->config('google_api_key'),
            'q' => $text,
            'source' => $sourceLang,
            'target' => $targetLang,
            'format' => 'text',
        ]);

        if ($response->successful()) {
            $data = $response->json();

            if (! empty($data['data']['translations'][0]['translatedText'])) {
                return TranslationResult::success(
                    translated: $data['data']['translations'][0]['translatedText'],
                    source: $this->key(),
                    confidence: (float) ($data['data']['translations'][0]['confidence'] ?? 0.9),
                    processingTime: $this->elapsed($startTime),
                );
            }
        }

        return TranslationResult::failure('Google Translate API failed');
    }
}
