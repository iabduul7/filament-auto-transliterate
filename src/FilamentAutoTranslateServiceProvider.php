<?php

namespace Iabduul7\FilamentAutoTranslate;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Iabduul7\FilamentAutoTranslate\Macros\TranslatableMacro;
use Iabduul7\FilamentAutoTranslate\Services\TranslationService;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentAutoTranslateServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-auto-translate';

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
            Js::make('filament-auto-translate', __DIR__.'/../resources/dist/filament-auto-translate.js'),
            Css::make('filament-auto-translate', __DIR__.'/../resources/dist/filament-auto-translate.css'),
        ], package: 'iabduul7/filament-auto-translate');
    }
}
