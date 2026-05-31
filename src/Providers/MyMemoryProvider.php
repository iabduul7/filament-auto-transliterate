<?php

namespace Iabduul7\FilamentAutoTransliterate\Providers;

use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;
use Illuminate\Support\Facades\Http;

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
        $startTime = microtime(true);

        $query = [
            'q' => $text,
            'langpair' => "{$sourceLang}|{$targetLang}",
        ];

        // Sending an email raises the daily quota; optional.
        if ($email = $this->config('mymemory_email')) {
            $query['de'] = $email;
        }

        $response = Http::timeout($this->timeout())->get('https://api.mymemory.translated.net/get', $query);

        if ($response->successful()) {
            $data = $response->json();

            if (($data['responseStatus'] ?? null) == 200 && ! empty($data['responseData']['translatedText'])) {
                return TranslationResult::success(
                    translated: $data['responseData']['translatedText'],
                    source: $this->key(),
                    confidence: (float) ($data['responseData']['match'] ?? 0.8),
                    processingTime: $this->elapsed($startTime),
                );
            }
        }

        return TranslationResult::failure('MyMemory API failed');
    }
}
