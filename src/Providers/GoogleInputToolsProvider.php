<?php

namespace Iabduul7\FilamentAutoTransliterate\Providers;

use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;
use Iabduul7\FilamentAutoTransliterate\Support\Languages;
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

        // Input-method code, e.g. "ur-t-i0-und" for Urdu transliteration. A
        // language entry may override this (config `languages.{code}.itc`) for
        // a code Input Tools expects to differ from the "{code}-t-i0-und"
        // convention.
        $itc = Languages::all()[$targetLang]['itc'] ?? "{$targetLang}-t-i0-und";

        // Same request cost regardless of `num`; the extra candidates become
        // `alternatives` below (doc 04) for a future candidate-picker UI.
        $num = max(1, (int) $this->config('suggestions_per_word', 4));

        $response = Http::timeout($this->timeout())->get('https://inputtools.google.com/request', [
            'text' => $text,
            'itc' => $itc,
            'num' => $num,
            'cp' => 0,
            'cs' => 1,
            'ie' => 'utf-8',
            'oe' => 'utf-8',
        ]);

        if ($response->successful()) {
            $data = $response->json();

            // Response: ["SUCCESS", [["source", ["suggestion1", ...], ...]]]
            if (isset($data[0]) && $data[0] === 'SUCCESS' && isset($data[1][0][1][0])) {
                $segments = $data[1];
                $result = '';
                foreach ($segments as $segment) {
                    $result .= ($segment[1][0] ?? '').' ';
                }

                $translated = trim($result);

                if ($translated !== '') {
                    // Alternatives only make sense for a single-segment
                    // response — with multiple segments, "alternative for the
                    // phrase" would mean a cartesian product of per-segment
                    // candidates, which isn't a meaningful single list.
                    $alternatives = count($segments) === 1
                        ? array_values(array_slice((array) ($segments[0][1] ?? []), 1))
                        : [];

                    return TranslationResult::success(
                        translated: $translated,
                        source: $this->key(),
                        confidence: 0.95,
                        processingTime: $this->elapsed($startTime),
                        alternatives: $alternatives,
                    );
                }
            }
        }

        return TranslationResult::failure('Google Input Tools returned no transliteration');
    }
}
