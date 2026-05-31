<?php

namespace Iabduul7\FilamentAutoTransliterate;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;

class FilamentAutoTransliteratePlugin implements Plugin
{
    protected bool $showToggle = true;

    protected bool $injectCsrfMeta = true;

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

    public function register(Panel $panel): void
    {
        if ($this->injectCsrfMeta) {
            $panel->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<meta name="csrf-token" content="'.csrf_token().'">',
            );
        }

        if ($this->showToggle) {
            $panel->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => view('filament-auto-transliterate::hooks.toggle')->render(),
            );
        }
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
