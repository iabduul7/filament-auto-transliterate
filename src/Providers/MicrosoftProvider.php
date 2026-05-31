<?php

namespace Iabduul7\FilamentAutoTransliterate\Providers;

use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft Translator — meaning-based translation (free tier ~2M chars/month).
 */
class MicrosoftProvider extends AbstractProvider
{
    public function isConfigured(): bool
    {
        return (bool) $this->config('microsoft_key') && (bool) $this->config('microsoft_endpoint');
    }

    public function key(): string
    {
        return 'microsoft';
    }

    public function translate(string $text, string $sourceLang, string $targetLang): TranslationResult
    {
        $startTime = microtime(true);

        $endpoint = rtrim((string) $this->config('microsoft_endpoint'), '/');
        $key = (string) $this->config('microsoft_key');

        $response = Http::timeout($this->timeout())
            ->withHeaders([
                'Ocp-Apim-Subscription-Key' => $key,
                'Content-Type' => 'application/json',
            ])
            ->post("{$endpoint}/translate?api-version=3.0&from={$sourceLang}&to={$targetLang}", [
                ['text' => $text],
            ]);

        if ($response->successful()) {
            $data = $response->json();

            if (! empty($data[0]['translations'][0]['text'])) {
                return TranslationResult::success(
                    translated: $data[0]['translations'][0]['text'],
                    source: $this->key(),
                    confidence: (float) ($data[0]['translations'][0]['confidence'] ?? 0.9),
                    processingTime: $this->elapsed($startTime),
                );
            }
        }

        return TranslationResult::failure('Microsoft Translator API failed');
    }
}
