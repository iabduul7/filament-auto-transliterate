<?php

namespace Iabduul7\FilamentAutoTransliterate\Providers;

use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;

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
        return $this->guard(function () use ($text, $sourceLang, $targetLang): TranslationResult {
            $startTime = microtime(true);

            $endpoint = rtrim((string) $this->config('microsoft_endpoint'), '/');

            $headers = [
                'Ocp-Apim-Subscription-Key' => (string) $this->config('microsoft_key'),
                'Content-Type' => 'application/json',
            ];

            // Regional / multi-service Azure resources require the region
            // header; the global endpoint does not. Sent only when configured.
            if ($region = (string) $this->config('microsoft_region', '')) {
                $headers['Ocp-Apim-Subscription-Region'] = $region;
            }

            $query = http_build_query([
                'api-version' => '3.0',
                'from' => $sourceLang,
                'to' => $targetLang,
            ]);

            $response = $this->http()
                ->withHeaders($headers)
                ->post("{$endpoint}/translate?{$query}", [
                    ['text' => $text],
                ]);

            if ($error = $this->httpError($response, 'Microsoft Translator')) {
                return $error;
            }

            $data = $response->json();
            $translation = is_array($data) ? ($data[0]['translations'][0] ?? null) : null;

            if (! is_array($translation) || ! isset($translation['text']) || ! is_string($translation['text'])) {
                return TranslationResult::failure('Microsoft Translator returned a malformed response');
            }

            if (trim($translation['text']) === '') {
                return TranslationResult::miss('Microsoft Translator returned an empty translation');
            }

            return TranslationResult::success(
                translated: $translation['text'],
                source: $this->key(),
                confidence: (float) ($translation['confidence'] ?? 0.9),
                processingTime: $this->elapsed($startTime),
            );
        });
    }
}
