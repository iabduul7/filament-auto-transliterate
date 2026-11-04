<?php

namespace Iabduul7\FilamentAutoTransliterate\Providers;

use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;
use Iabduul7\FilamentAutoTransliterate\Support\Languages;

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
        return $this->guard(function () use ($text, $targetLang): TranslationResult {
            $startTime = microtime(true);

            // Input-method code, e.g. "ur-t-i0-und" for Urdu transliteration. A
            // language entry may override this (config `languages.{code}.itc`) for
            // a code Input Tools expects to differ from the "{code}-t-i0-und"
            // convention.
            $itc = Languages::all()[$targetLang]['itc'] ?? "{$targetLang}-t-i0-und";

            // Same request cost regardless of `num`; the extra candidates become
            // `alternatives` below (doc 04) for a future candidate-picker UI.
            $num = max(1, (int) $this->config('suggestions_per_word', 4));

            $response = $this->http()->get('https://inputtools.google.com/request', [
                'text' => $text,
                'itc' => $itc,
                'num' => $num,
                'cp' => 0,
                'cs' => 1,
                'ie' => 'utf-8',
                'oe' => 'utf-8',
            ]);

            if ($error = $this->httpError($response, 'Google Input Tools')) {
                return $error;
            }

            // Response: ["SUCCESS", [["source", ["suggestion1", ...], ...], ...]]
            $data = $response->json();

            if (! is_array($data) || ! isset($data[0]) || ! is_string($data[0])) {
                return TranslationResult::failure('Google Input Tools returned a malformed response');
            }

            if ($data[0] !== 'SUCCESS') {
                return TranslationResult::failure('Google Input Tools returned status '.$data[0]);
            }

            if (! isset($data[1]) || ! is_array($data[1])) {
                return TranslationResult::failure('Google Input Tools returned a malformed response');
            }

            $segments = array_values($data[1]);
            $parts = [];
            $converted = 0;
            $firstCandidates = [];

            foreach ($segments as $segment) {
                $source = is_array($segment) && isset($segment[0]) && is_string($segment[0]) ? $segment[0] : '';
                $candidates = is_array($segment) && isset($segment[1]) && is_array($segment[1])
                    ? array_values(array_filter($segment[1], fn ($c) => is_string($c) && $c !== ''))
                    : [];

                if ($candidates !== []) {
                    $firstCandidates = $firstCandidates ?: $candidates;
                    $parts[] = $candidates[0];
                    $converted++;
                } elseif ($source !== '') {
                    // No candidates for this token: keep what the user typed
                    // rather than silently dropping part of their text.
                    $parts[] = $source;
                }
            }

            if ($converted === 0) {
                return TranslationResult::miss('Google Input Tools returned no candidates');
            }

            // Alternatives only make sense for a single-segment response —
            // with multiple segments, "alternative for the phrase" would mean a
            // cartesian product of per-segment candidates, which isn't a
            // meaningful single list.
            $alternatives = [];
            if (count($segments) === 1) {
                $alternatives = array_slice($firstCandidates, 1);
            }

            return TranslationResult::success(
                translated: implode(' ', $parts),
                source: $this->key(),
                // Lower confidence when some tokens were passed through as typed.
                confidence: $converted === count($parts) ? 0.95 : 0.7,
                processingTime: $this->elapsed($startTime),
                alternatives: $alternatives,
            );
        });
    }
}
