<?php

namespace Iabduul7\FilamentAutoTransliterate\Providers;

use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;

/**
 * MyMemory — free meaning-based translation (about 10k chars/day anonymously).
 */
class MyMemoryProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'mymemory';
    }

    public function translate(string $text, string $sourceLang, string $targetLang): TranslationResult
    {
        return $this->guard(function () use ($text, $sourceLang, $targetLang): TranslationResult {
            $startTime = microtime(true);

            $query = [
                'q' => $text,
                'langpair' => "{$sourceLang}|{$targetLang}",
            ];

            // Sending an email raises the daily quota; optional.
            if ($email = $this->config('mymemory_email')) {
                $query['de'] = $email;
            }

            $response = $this->http()->get('https://api.mymemory.translated.net/get', $query);

            if ($error = $this->httpError($response, 'MyMemory')) {
                return $error;
            }

            $data = $response->json();

            if (! is_array($data) || ! isset($data['responseStatus'])) {
                return TranslationResult::failure('MyMemory returned a malformed response');
            }

            // MyMemory also reports rate limiting / bad requests in the body.
            if ((int) $data['responseStatus'] !== 200) {
                return TranslationResult::failure('MyMemory responseStatus '.(int) $data['responseStatus']);
            }

            $translated = $data['responseData']['translatedText'] ?? null;

            if (! is_string($translated)) {
                return TranslationResult::failure('MyMemory returned a malformed response');
            }

            // Out of free quota: MyMemory answers 200 with a warning string in
            // place of the translation. That is not a translation (and must
            // never be cached or shown), so treat it as a miss.
            if (stripos($translated, 'MYMEMORY WARNING') !== false) {
                return TranslationResult::miss('MyMemory quota exhausted');
            }

            // An echo of the input means it found nothing useful.
            if (trim($translated) === '' || mb_strtolower(trim($translated)) === mb_strtolower(trim($text))) {
                return TranslationResult::miss('MyMemory returned no translation');
            }

            return TranslationResult::success(
                translated: $translated,
                source: $this->key(),
                confidence: (float) ($data['responseData']['match'] ?? 0.8),
                processingTime: $this->elapsed($startTime),
            );
        });
    }
}
