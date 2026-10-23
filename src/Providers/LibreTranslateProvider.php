<?php

namespace Iabduul7\FilamentAutoTransliterate\Providers;

use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;

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
        return $this->guard(function () use ($text, $sourceLang, $targetLang): TranslationResult {
            $startTime = microtime(true);

            $endpoint = rtrim((string) $this->config('libretranslate_url', 'http://localhost:5000'), '/');

            $payload = [
                'q' => $text,
                'source' => $sourceLang,
                'target' => $targetLang,
                'format' => 'text',
            ];

            // Self-hosted instances usually run without a key; only send one
            // when it is actually configured.
            if ($key = (string) $this->config('libretranslate_key', '')) {
                $payload['api_key'] = $key;
            }

            $response = $this->http()->post("{$endpoint}/translate", $payload);

            if ($error = $this->httpError($response, 'LibreTranslate')) {
                return $error;
            }

            $data = $response->json();

            if (! is_array($data) || ! isset($data['translatedText']) || ! is_string($data['translatedText'])) {
                return TranslationResult::failure('LibreTranslate returned a malformed response');
            }

            if (trim($data['translatedText']) === '') {
                return TranslationResult::miss('LibreTranslate returned an empty translation');
            }

            return TranslationResult::success(
                translated: $data['translatedText'],
                source: $this->key(),
                confidence: 0.85,
                processingTime: $this->elapsed($startTime),
            );
        });
    }
}
