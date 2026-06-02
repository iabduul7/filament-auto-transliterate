<?php

namespace Iabduul7\FilamentAutoTransliterate\Http\Controllers;

use Iabduul7\FilamentAutoTransliterate\Models\TranslationCache;
use Iabduul7\FilamentAutoTransliterate\Services\TranslationService;
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
            'target_lang' => 'nullable|string|max:10',
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
            'target_lang' => 'nullable|string|max:10',
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
