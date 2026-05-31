<?php

use Iabduul7\FilamentAutoTranslate\Http\Controllers\TranslationController;
use Illuminate\Support\Facades\Route;

$config = config('filament-auto-translate.route', []);

$middleware = $config['middleware'] ?? ['web', 'auth'];

if (! empty($config['throttle'])) {
    $middleware[] = 'throttle:'.$config['throttle'];
}

Route::prefix($config['prefix'] ?? 'filament-auto-translate')
    ->middleware($middleware)
    ->name('filament-auto-translate.')
    ->group(function () {
        Route::post('/translate', [TranslationController::class, 'translate'])->name('translate');
        Route::post('/batch-translate', [TranslationController::class, 'batchTranslate'])->name('batch');
        Route::get('/provider-status', [TranslationController::class, 'providerStatus'])->name('status');
        Route::get('/stats', [TranslationController::class, 'stats'])->name('stats');
    });
