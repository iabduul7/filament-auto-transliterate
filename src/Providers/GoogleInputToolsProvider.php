<?php

namespace Iabduul7\FilamentAutoTransliterate\Providers;

use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;
use Illuminate\Support\Facades\Http;

/**
 * Google Input Tools (unofficial) — the transliteration engine.
 *
 * Converts Roman script to the target script by sound, e.g.
 * "yeh aaj nahi aya" -> "یہ آج نہیں آیا". This is the default provider for
 * transliterate mode and must NOT be used for meaning-based translation.
 */
class GoogleInputToolsProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'google_input_tools';
    }

    public function translate(string $text, string $sourceLang, string $targetLang): TranslationResult
    {
        $startTime = microtime(true);

        // Input-method code, e.g. "ur-t-i0-und" for Urdu transliteration.
        $itc = "{$targetLang}-t-i0-und";

        $response = Http::timeout($this->timeout())->get('https://inputtools.google.com/request', [
            'text' => $text,
            'itc' => $itc,
            'num' => 1,
            'cp' => 0,
            'cs' => 1,
            'ie' => 'utf-8',
            'oe' => 'utf-8',
        ]);

        if ($response->successful()) {
            $data = $response->json();

            // Response: ["SUCCESS", [["source", ["suggestion1", ...], ...]]]
            if (isset($data[0]) && $data[0] === 'SUCCESS' && isset($data[1][0][1][0])) {
                $result = '';
                foreach ($data[1] as $segment) {
                    $result .= ($segment[1][0] ?? '').' ';
                }

                $translated = trim($result);

                if ($translated !== '') {
                    return TranslationResult::success(
                        translated: $translated,
                        source: $this->key(),
                        confidence: 0.95,
                        processingTime: $this->elapsed($startTime),
                    );
                }
            }
        }

        return TranslationResult::failure('Google Input Tools returned no transliteration');
    }
}
