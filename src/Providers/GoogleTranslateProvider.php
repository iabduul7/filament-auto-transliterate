<?php

namespace Iabduul7\FilamentAutoTransliterate\Providers;

use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;

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
        return $this->guard(function () use ($text, $sourceLang, $targetLang): TranslationResult {
            $startTime = microtime(true);

            // v2 convention: the API key travels as a query parameter, not in
            // the JSON body.
            $url = 'https://translation.googleapis.com/language/translate/v2?'.http_build_query([
                'key' => (string) $this->config('google_api_key'),
            ]);

            $response = $this->http()->post($url, [
                'q' => $text,
                'source' => $sourceLang,
                'target' => $targetLang,
                'format' => 'text',
            ]);

            if ($error = $this->httpError($response, 'Google Translate')) {
                return $error;
            }

            $data = $response->json();
            $translation = is_array($data) ? ($data['data']['translations'][0] ?? null) : null;

            if (! is_array($translation) || ! isset($translation['translatedText']) || ! is_string($translation['translatedText'])) {
                return TranslationResult::failure('Google Translate returned a malformed response');
            }

            if (trim($translation['translatedText']) === '') {
                return TranslationResult::miss('Google Translate returned an empty translation');
            }

            return TranslationResult::success(
                translated: $translation['translatedText'],
                source: $this->key(),
                confidence: (float) ($translation['confidence'] ?? 0.9),
                processingTime: $this->elapsed($startTime),
            );
        });
    }
}
