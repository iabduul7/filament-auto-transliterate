<?php

use Iabduul7\FilamentAutoTransliterate\FilamentAutoTransliterateServiceProvider as Provider;
use Iabduul7\FilamentAutoTransliterate\Http\Controllers\TranslationController;
use Illuminate\Support\Facades\Route;

$config = config('filament-auto-transliterate.route', []);

// Built at registration time so the guard option takes effect; an explicit
// `route.middleware` array still wins and is used verbatim.
$guard = $config['guard'] ?? null;
$middleware = (array) ($config['middleware'] ?? ['web', 'auth'.(filled($guard) ? ':'.$guard : '')]);

// Named limiter (registered by the service provider): per user, falling back
// to IP. Omitted entirely when `route.throttle` is empty.
if (Provider::parseThrottle($config['throttle'] ?? null) !== null) {
    $middleware[] = 'throttle:'.Provider::RATE_LIMITER;
}

$learnMiddleware = Provider::parseThrottle(config('filament-auto-transliterate.learn.throttle')) !== null
    ? ['throttle:'.Provider::LEARN_RATE_LIMITER]
    : [];

Route::prefix($config['prefix'] ?? 'filament-auto-transliterate')
    ->middleware($middleware)
    ->name('filament-auto-transliterate.')
    ->group(function () use ($learnMiddleware) {
        Route::post('/translate', [TranslationController::class, 'translate'])->name('translate');
        Route::post('/batch-translate', [TranslationController::class, 'batchTranslate'])->name('batch');
        Route::post('/learn', [TranslationController::class, 'learn'])->middleware($learnMiddleware)->name('learn');
        Route::get('/provider-status', [TranslationController::class, 'providerStatus'])->name('status');
        Route::get('/stats', [TranslationController::class, 'stats'])->name('stats');
    });
