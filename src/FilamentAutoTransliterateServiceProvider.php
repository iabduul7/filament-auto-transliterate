<?php

namespace Iabduul7\FilamentAutoTransliterate;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Iabduul7\FilamentAutoTransliterate\Macros\TranslatableMacro;
use Iabduul7\FilamentAutoTransliterate\Services\TranslationService;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentAutoTransliterateServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-auto-transliterate';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews()
            ->hasRoute('web')
            ->hasMigration('create_translation_cache_table');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(TranslationService::class);
    }

    public function packageBooted(): void
    {
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
