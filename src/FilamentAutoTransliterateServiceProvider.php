<?php

namespace Iabduul7\FilamentAutoTransliterate;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Iabduul7\FilamentAutoTransliterate\Commands\PruneTranslationCacheCommand;
use Iabduul7\FilamentAutoTransliterate\Macros\TranslatableMacro;
use Iabduul7\FilamentAutoTransliterate\Services\TranslationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentAutoTransliterateServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-auto-transliterate';

    /** Named limiter for every route (config: route.throttle). */
    public const RATE_LIMITER = 'filament-auto-transliterate';

    /** Extra, stricter limiter for /learn (config: learn.throttle). */
    public const LEARN_RATE_LIMITER = 'filament-auto-transliterate-learn';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews()
            ->hasRoute('web')
            ->hasCommand(PruneTranslationCacheCommand::class)
            ->hasMigrations([
                'create_translation_cache_table',
                'add_user_id_and_unique_index_to_translation_cache_table',
            ])
            ->hasInstallCommand(function (InstallCommand $command) {
                // `php artisan filament-auto-transliterate:install` publishes the
                // config + migration and offers to run migrations, so consumers
                // can get the cache table without hand-copying files.
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('iabduul7/filament-auto-transliterate');
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(TranslationService::class);

        // One instance per provider so e.g. dictionary files are decoded once
        // per request lifecycle rather than on every resolve.
        foreach ((array) config('filament-auto-transliterate.provider_map', []) as $class) {
            if (is_string($class) && class_exists($class)) {
                $this->app->singleton($class);
            }
        }
    }

    /**
     * Parse a "max,minutes" throttle string. Null when empty/invalid (= no limit).
     *
     * @return array{0:int,1:int}|null
     */
    public static function parseThrottle(mixed $value): ?array
    {
        $value = is_int($value) ? (string) $value : $value;

        if (! is_string($value) || ! preg_match('/^\s*(\d+)\s*(?:,\s*(\d+))?\s*$/', $value, $m)) {
            return null;
        }

        $max = (int) $m[1];
        $minutes = max(1, (int) ($m[2] ?? 1));

        return $max > 0 ? [$max, $minutes] : null;
    }

    protected function registerRateLimiters(): void
    {
        $limiter = fn (string $configKey) => function (Request $request) use ($configKey) {
            $parsed = static::parseThrottle(config($configKey));

            if ($parsed === null) {
                return Limit::none();
            }

            return Limit::perMinutes($parsed[1], $parsed[0])
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        };

        RateLimiter::for(static::RATE_LIMITER, $limiter('filament-auto-transliterate.route.throttle'));
        RateLimiter::for(static::LEARN_RATE_LIMITER, $limiter('filament-auto-transliterate.learn.throttle'));
    }

    public function packageBooted(): void
    {
        $this->registerRateLimiters();

        // The ->translatable() macro on form components.
        TranslatableMacro::register();

        // Compiled JS/CSS, auto-injected into every panel page. Build them with
        // `npm run build`; the prebuilt files ship in resources/dist.
        FilamentAsset::register([
            Js::make('filament-auto-transliterate', __DIR__.'/../resources/dist/filament-auto-transliterate.js'),
            Css::make('filament-auto-transliterate', __DIR__.'/../resources/dist/filament-auto-transliterate.css'),
        ], package: 'iabduul7/filament-auto-transliterate');
    }
}
