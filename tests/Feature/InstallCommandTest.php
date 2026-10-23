<?php

use Iabduul7\FilamentAutoTransliterate\FilamentAutoTransliterateServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

it('registers the install command', function () {
    expect(Artisan::all())->toHaveKey('filament-auto-transliterate:install');
});

it('exposes the config under the tag the install command publishes', function () {
    $paths = ServiceProvider::pathsToPublish(FilamentAutoTransliterateServiceProvider::class, 'filament-auto-transliterate-config');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('filament-auto-transliterate.php')
        ->and(array_values($paths)[0])->toEndWith('filament-auto-transliterate.php');
});

it('exposes the migration stubs under the tag the install command publishes', function () {
    $paths = ServiceProvider::pathsToPublish(FilamentAutoTransliterateServiceProvider::class, 'filament-auto-transliterate-migrations');
    $sources = array_map('basename', array_keys($paths));

    expect($sources)->toContain('create_translation_cache_table.php.stub')
        ->toContain('add_user_id_and_unique_index_to_translation_cache_table.php.stub');

    // Destinations are real, timestamped .php migrations.
    foreach ($paths as $destination) {
        expect(basename($destination))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_.+\.php$/');
    }
});

it('registers the prune command', function () {
    expect(Artisan::all())->toHaveKey('filament-auto-transliterate:prune');
});

it('ships the new security-related config keys with safe defaults', function () {
    expect(config('filament-auto-transliterate.allowed_modes'))->toBe(['transliterate', 'translate'])
        ->and(config('filament-auto-transliterate.route.guard'))->toBeNull()
        ->and(config('filament-auto-transliterate.route.middleware'))->toBeNull()
        ->and(config('filament-auto-transliterate.ops_endpoints.enabled'))->toBeFalse()
        ->and(config('filament-auto-transliterate.ops_endpoints.probe_providers'))->toBeFalse()
        ->and(config('filament-auto-transliterate.learn.max_original_length'))->toBe(200)
        ->and(config('filament-auto-transliterate.learn.throttle'))->toBe('20,1')
        ->and(config('filament-auto-transliterate.cache_ttl_days'))->toBeNull();
});
