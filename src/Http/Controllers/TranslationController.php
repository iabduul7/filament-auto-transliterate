<?php

namespace Iabduul7\FilamentAutoTransliterate\Http\Controllers;

use Iabduul7\FilamentAutoTransliterate\Models\TranslationCache;
use Iabduul7\FilamentAutoTransliterate\Services\TranslationService;
use Iabduul7\FilamentAutoTransliterate\Support\Languages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TranslationController extends Controller
{
    public function __construct(private readonly TranslationService $translationService) {}

    public function translate(Request $request): JsonResponse
    {
        $maxLength = (int) config('filament-auto-transliterate.max_text_length', 1000);

        $validator = Validator::make($request->all(), [
            'text' => "required|string|max:{$maxLength}",
            'target_lang' => ['nullable', 'string', 'max:10', Rule::in(Languages::codes())],
            'mode' => ['nullable', Rule::in(['transliterate', 'translate'])],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid input',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->translationService->translate(
                trim((string) $request->input('text')),
                $request->input('target_lang'),
                $request->input('mode'),
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            return $this->error('Translation failed', $e);
        }
    }

    public function batchTranslate(Request $request): JsonResponse
    {
        $maxLength = (int) config('filament-auto-transliterate.max_text_length', 1000);
        $maxBatch = (int) config('filament-auto-transliterate.max_batch_size', 10);

        $validator = Validator::make($request->all(), [
            'texts' => "required|array|max:{$maxBatch}",
            'texts.*' => "required|string|max:{$maxLength}",
            'target_lang' => ['nullable', 'string', 'max:10', Rule::in(Languages::codes())],
            'mode' => ['nullable', Rule::in(['transliterate', 'translate'])],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid input',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $results = [];
            foreach ($request->input('texts') as $index => $text) {
                $results[$index] = $this->translationService->translate(
                    trim((string) $text),
                    $request->input('target_lang'),
                    $request->input('mode'),
                );
            }

            return response()->json([
                'success' => true,
                'results' => $results,
                'total' => count($results),
                'successful' => count(array_filter($results, fn ($r) => $r['success'])),
            ]);
        } catch (\Throwable $e) {
            return $this->error('Batch translation failed', $e);
        }
    }

    /**
     * Store a user's correction of an applied word (docs/02-self-improvement.md).
     * 404s when disabled via config so the JS overlay knows never to send.
     */
    public function learn(Request $request): JsonResponse
    {
        if (! config('filament-auto-transliterate.learn.enabled', true)) {
            abort(404);
        }

        $maxLength = (int) config('filament-auto-transliterate.max_text_length', 1000);
        $targetLang = Languages::resolve($request->input('target_lang'));

        $validator = Validator::make($request->all(), [
            'original' => [
                'required',
                'string',
                "max:{$maxLength}",
                function (string $attribute, mixed $value, \Closure $fail) use ($targetLang) {
                    // You can't "correct" something that was never romanized.
                    if (is_string($value) && $this->matchesTargetScript($value, $targetLang)) {
                        $fail('The original text must not already be in the target script.');
                    }
                },
            ],
            'corrected' => [
                'required',
                'string',
                "max:{$maxLength}",
                'different:original',
                function (string $attribute, mixed $value, \Closure $fail) use ($targetLang) {
                    // Main defense against garbage writes: the correction must
                    // actually be in the target script.
                    if (is_string($value) && ! $this->matchesTargetScript($value, $targetLang)) {
                        $fail('The corrected text must be in the target script.');
                    }
                },
            ],
            'target_lang' => ['nullable', 'string', Rule::in(Languages::codes())],
            'mode' => ['nullable', Rule::in(['transliterate', 'translate'])],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid input',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->translationService->learn(
                (string) $request->input('original'),
                (string) $request->input('corrected'),
                $request->input('target_lang'),
                $request->input('mode'),
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            return $this->error('Failed to store correction', $e);
        }
    }

    private function matchesTargetScript(string $text, string $targetLang): bool
    {
        $pattern = config('filament-auto-transliterate.target_script_pattern')
            ?? Languages::phpScriptPattern($targetLang);

        return is_string($pattern) && $pattern !== '' && preg_match($pattern, $text) > 0;
    }

    public function providerStatus(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'providers' => $this->translationService->providerStatus(),
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Throwable $e) {
            return $this->error('Failed to get provider status', $e);
        }
    }

    public function stats(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'stats' => TranslationCache::stats(),
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Throwable $e) {
            return $this->error('Failed to get translation stats', $e);
        }
    }

    private function error(string $message, \Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
        ], 500);
    }
}
