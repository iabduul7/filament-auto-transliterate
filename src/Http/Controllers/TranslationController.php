<?php

namespace Iabduul7\FilamentAutoTransliterate\Http\Controllers;

use Iabduul7\FilamentAutoTransliterate\Enums\TranslationMode;
use Iabduul7\FilamentAutoTransliterate\FilamentAutoTransliteratePlugin;
use Iabduul7\FilamentAutoTransliterate\Models\TranslationCache;
use Iabduul7\FilamentAutoTransliterate\Services\TranslationService;
use Iabduul7\FilamentAutoTransliterate\Support\Languages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorContract;

class TranslationController extends Controller
{
    public function __construct(private readonly TranslationService $translationService)
    {
        // Optional host authorization hook (FilamentAutoTransliteratePlugin::
        // authorizeUsing()). Runs after the route's auth middleware.
        $this->middleware(function (Request $request, \Closure $next) {
            abort_unless(FilamentAutoTransliteratePlugin::isAuthorized($request), 403);

            return $next($request);
        });

        // Operational endpoints are opt-in: they expose cache/provider internals.
        $this->middleware(function (Request $request, \Closure $next) {
            abort_unless(config('filament-auto-transliterate.ops_endpoints.enabled', false), 404);

            return $next($request);
        })->only(['stats', 'providerStatus']);
    }

    public function translate(Request $request): JsonResponse
    {
        $maxLength = (int) config('filament-auto-transliterate.max_text_length', 1000);

        $validator = Validator::make($request->all(), [
            'text' => "required|string|max:{$maxLength}",
            'target_lang' => $this->targetLangRules(),
            'mode' => $this->modeRules(),
        ]);
        $this->validateEffectiveMode($validator, $request);

        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $targetLang = Languages::resolve($request->input('target_lang'));

        try {
            $result = $this->translationService->translate(
                trim((string) $request->input('text')),
                $targetLang,
                $request->input('mode'),
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            return $this->error('Translation failed', $e);
        }
    }

    /**
     * Response shape:
     *   success    bool   true when AT LEAST ONE item succeeded (false when every
     *                     item failed) - a partial batch is still `success: true`.
     *   results    array  per-item result objects, keyed by the input index.
     *   total      int    number of items processed.
     *   successful int    number of items whose own `success` is true.
     */
    public function batchTranslate(Request $request): JsonResponse
    {
        $maxLength = (int) config('filament-auto-transliterate.max_text_length', 1000);
        $maxBatch = (int) config('filament-auto-transliterate.max_batch_size', 10);

        $validator = Validator::make($request->all(), [
            'texts' => "required|array|max:{$maxBatch}",
            'texts.*' => "required|string|max:{$maxLength}",
            'target_lang' => $this->targetLangRules(),
            'mode' => $this->modeRules(),
        ]);
        $this->validateEffectiveMode($validator, $request);

        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $targetLang = Languages::resolve($request->input('target_lang'));

        try {
            $results = [];
            foreach ($request->input('texts') as $index => $text) {
                $results[$index] = $this->translationService->translate(
                    trim((string) $text),
                    $targetLang,
                    $request->input('mode'),
                );
            }

            $successful = count(array_filter($results, fn ($r) => (bool) ($r['success'] ?? false)));

            return response()->json([
                'success' => $successful > 0,
                'results' => $results,
                'total' => count($results),
                'successful' => $successful,
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
        $maxOriginal = (int) config('filament-auto-transliterate.learn.max_original_length', 200);

        $validator = Validator::make($request->all(), [
            'original' => ['required', 'string', "max:{$maxOriginal}"],
            'corrected' => ['required', 'string', "max:{$maxLength}", 'different:original'],
            'target_lang' => $this->targetLangRules(),
            'mode' => $this->modeRules(),
        ]);
        $this->validateEffectiveMode($validator, $request);

        // Script checks need the resolved language, so they run only once the
        // basic rules (incl. target_lang) have passed.
        $validator->after(function (ValidatorContract $validator) use ($request) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $targetLang = Languages::resolve($request->input('target_lang'));

            // You can't "correct" something that was never romanized.
            if ($this->matchesTargetScript((string) $request->input('original'), $targetLang)) {
                $validator->errors()->add('original', 'The original text must not already be in the target script.');
            }

            // Main defense against garbage writes: the correction must actually
            // be in the target script.
            if (! $this->isMostlyTargetScript((string) $request->input('corrected'), $targetLang)) {
                $validator->errors()->add('corrected', 'The corrected text must be in the target script.');
            }
        });

        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $targetLang = Languages::resolve($request->input('target_lang'));

        try {
            $result = $this->translationService->learn(
                (string) $request->input('original'),
                (string) $request->input('corrected'),
                $targetLang,
                $request->input('mode'),
            );

            if (($result['success'] ?? false) === true) {
                $this->recordCorrectingUser($request, $result);
            }

            return response()->json($result);
        } catch (\Throwable $e) {
            return $this->error('Failed to store correction', $e);
        }
    }

    public function providerStatus(): JsonResponse
    {
        try {
            $probe = (bool) config('filament-auto-transliterate.ops_endpoints.probe_providers', false);
            $live = $probe ? $this->translationService->providerStatus() : [];

            $providers = [];
            foreach ((array) config('filament-auto-transliterate.provider_map', []) as $key => $class) {
                $instance = is_string($class) && class_exists($class) ? app($class) : null;

                $entry = [
                    'configured' => $instance !== null && method_exists($instance, 'isConfigured') && $instance->isConfigured(),
                    'circuit_open' => $this->circuitOpen((string) $key),
                ];

                if ($probe) {
                    // Never forward provider/exception text: callers only learn
                    // whether the provider answered.
                    $ok = (bool) ($live[$key]['available'] ?? $live[$key]['ok'] ?? false);
                    $entry['ok'] = $ok;
                    $entry['error'] = $ok ? null : 'unavailable';
                }

                $providers[$key] = $entry;
            }

            return response()->json([
                'success' => true,
                'providers' => $providers,
                'probed' => $probe,
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

    /**
     * @return list<mixed>
     */
    private function targetLangRules(): array
    {
        return ['nullable', 'string', 'max:10', Rule::in(Languages::codes())];
    }

    /**
     * @return list<mixed>
     */
    private function modeRules(): array
    {
        return ['nullable', 'string', Rule::in($this->allowedModes())];
    }

    /**
     * Modes the endpoints accept: `allowed_modes`, limited to real modes.
     *
     * @return list<string>
     */
    private function allowedModes(): array
    {
        $real = array_map(fn (TranslationMode $m) => $m->value, TranslationMode::cases());

        return array_values(array_intersect((array) config('filament-auto-transliterate.allowed_modes', $real), $real));
    }

    /**
     * An omitted `mode` falls back to the configured default - which must also
     * be allowed, otherwise leaving it out would sidestep `allowed_modes`.
     */
    private function validateEffectiveMode(ValidatorContract $validator, Request $request): void
    {
        $validator->after(function (ValidatorContract $validator) use ($request) {
            if (filled($request->input('mode')) || $validator->errors()->has('mode')) {
                return;
            }

            if (! in_array(TranslationMode::default()->value, $this->allowedModes(), true)) {
                $validator->errors()->add('mode', 'The default mode is not allowed; specify an allowed mode.');
            }
        });
    }

    private function invalid(ValidatorContract $validator): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Invalid input',
            'errors' => $validator->errors(),
        ], 422);
    }

    /**
     * The service has no user parameter, so stamp the correcting user on the
     * freshly written correction row (hash lookup, user_correction rows only).
     *
     * @param  array<string, mixed>  $result
     */
    private function recordCorrectingUser(Request $request, array $result): void
    {
        $id = $request->user()?->getAuthIdentifier();

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return; // guests / non-numeric (uuid) keys don't fit the column
        }

        TranslationCache::stampCorrectionUser(
            trim((string) $request->input('original')),
            (string) ($result['target_lang'] ?? Languages::resolve($request->input('target_lang'))),
            (string) ($result['mode'] ?? TranslationMode::default()->value),
            (int) $id,
        );
    }

    private function circuitOpen(string $key): bool
    {
        $state = Cache::get("fat_provider_cb_{$key}");

        return is_array($state) && ($state['open_until'] ?? 0) > time();
    }

    private function matchesTargetScript(string $text, string $targetLang): bool
    {
        $pattern = $this->scriptPattern($targetLang);

        return $pattern !== null && preg_match($pattern, $text) > 0;
    }

    /**
     * The correction must contain target-script letters, and (at least
     * `learn.min_script_ratio` of) its letters must be in the target script.
     * Digits, punctuation, symbols and whitespace are ignored.
     */
    private function isMostlyTargetScript(string $text, string $targetLang): bool
    {
        $pattern = $this->scriptPattern($targetLang);

        if ($pattern === null) {
            return false;
        }

        // Letters and combining marks (e.g. vowel signs) only.
        preg_match_all('/[\p{L}\p{M}]/u', $text, $matches);
        $chars = $matches[0];

        if ($chars === []) {
            return false;
        }

        $inScript = count(array_filter($chars, fn (string $c) => preg_match($pattern, $c) > 0));
        $ratio = $inScript / count($chars);
        $min = (float) config('filament-auto-transliterate.learn.min_script_ratio', 1.0);

        return $inScript > 0 && $ratio >= $min;
    }

    /**
     * `target_script_pattern` is a global override; null or '' means "unset"
     * (same rule as TranslationService), deferring to the per-language pattern.
     */
    private function scriptPattern(string $targetLang): ?string
    {
        $override = config('filament-auto-transliterate.target_script_pattern');

        $pattern = is_string($override) && $override !== ''
            ? $override
            : Languages::phpScriptPattern($targetLang);

        return is_string($pattern) && $pattern !== '' ? $pattern : null;
    }

    private function error(string $message, \Throwable $e): JsonResponse
    {
        // Surface to the host's exception handler (Sentry, logs) while keeping
        // the message out of the response unless debugging.
        report($e);

        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
        ], 500);
    }
}
