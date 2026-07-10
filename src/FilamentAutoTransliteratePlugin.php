<?php

namespace Iabduul7\FilamentAutoTransliterate;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Iabduul7\FilamentAutoTransliterate\Support\Languages;

class FilamentAutoTransliteratePlugin implements Plugin
{
    protected bool $showToggle = true;

    protected bool $injectCsrfMeta = true;

    protected bool $languageSwitcher = true;

    public function getId(): string
    {
        return 'filament-auto-transliterate';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    /**
     * Show or hide the header on/off toggle (next to global search).
     */
    public function showToggle(bool $condition = true): static
    {
        $this->showToggle = $condition;

        return $this;
    }

    /**
     * Whether to inject a <meta name="csrf-token"> tag. Disable if your layout
     * already provides one.
     */
    public function injectCsrfMeta(bool $condition = true): static
    {
        $this->injectCsrfMeta = $condition;

        return $this;
    }

    /**
     * Show or hide the header language chip/dropdown (next to the on/off
     * toggle). Only relevant when more than one language is configured — see
     * resources/views/hooks/toggle.blade.php.
     */
    public function languageSwitcher(bool $condition = true): static
    {
        $this->languageSwitcher = $condition;

        return $this;
    }

    public function register(Panel $panel): void
    {
        if ($this->injectCsrfMeta) {
            $panel->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<meta name="csrf-token" content="'.csrf_token().'">',
            );
        }

        // Language table + defaults for the frontend (overlay + header switcher).
        // Injected at HEAD_END so it exists before the Filament-registered asset
        // bundle executes, regardless of load order.
        $panel->renderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => '<script>window.fatConfig = '.json_encode([
                'languages' => Languages::forJs(),
                'defaultTarget' => config('filament-auto-transliterate.target_language', 'ur'),
                'learnEnabled' => config('filament-auto-transliterate.learn.enabled', true),
            ]).';</script>',
        );

        if ($this->showToggle) {
            $panel->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => view('filament-auto-transliterate::hooks.toggle', [
                    'languageSwitcher' => $this->languageSwitcher,
                ])->render(),
            );
        }
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
