<?php

namespace Iabduul7\FilamentAutoTransliterate;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Iabduul7\FilamentAutoTransliterate\Support\Languages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

class FilamentAutoTransliteratePlugin implements Plugin
{
    protected bool $showToggle = true;

    protected bool $injectCsrfMeta = true;

    protected bool $languageSwitcher = true;

    protected string $toggleRenderHook = PanelsRenderHook::GLOBAL_SEARCH_BEFORE;

    protected Closure|string|null $scriptNonce = null;

    /**
     * Host authorization callback for the HTTP endpoints (static: the routes are
     * panel-independent). Receives the Request, returns true to allow.
     */
    protected static ?Closure $authorizeUsing = null;

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

    /**
     * Render hook the on/off toggle (+ language chip) is injected at. Defaults
     * to PanelsRenderHook::GLOBAL_SEARCH_BEFORE; move it for layouts with a
     * custom topbar, a sidebar-only or a simple-layout panel.
     */
    public function toggleRenderHook(string $hook): static
    {
        $this->toggleRenderHook = $hook;

        return $this;
    }

    /**
     * CSP nonce for the inline `window.fatConfig` script. By default the nonce
     * Laravel's Vite integration exposes (Vite::useCspNonce() / cspNonce(), the
     * same one Filament uses for its own scripts) is applied automatically; use
     * this to supply a different one. Accepts a string or a closure returning one.
     */
    public function scriptNonce(Closure|string|null $nonce): static
    {
        $this->scriptNonce = $nonce;

        return $this;
    }

    /**
     * Gate every package endpoint behind a host check, e.g.
     *   FilamentAutoTransliteratePlugin::authorizeUsing(fn (Request $r) => $r->user()?->can('use-transliteration'));
     * A falsy result yields 403. Pass null to remove the hook.
     *
     * @param  (Closure(Request): mixed)|null  $callback
     */
    public static function authorizeUsing(?Closure $callback): void
    {
        static::$authorizeUsing = $callback;
    }

    /**
     * True when no authorize hook is set, or the hook approves the request.
     */
    public static function isAuthorized(Request $request): bool
    {
        return static::$authorizeUsing === null || (bool) (static::$authorizeUsing)($request);
    }

    protected function resolveNonce(): ?string
    {
        $nonce = $this->scriptNonce instanceof Closure ? ($this->scriptNonce)() : $this->scriptNonce;
        $nonce ??= Vite::cspNonce();

        return filled($nonce) ? (string) $nonce : null;
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
            function (): string {
                $json = json_encode([
                    'languages' => Languages::forJs(),
                    'defaultTarget' => config('filament-auto-transliterate.target_language', 'ur'),
                    'learnEnabled' => config('filament-auto-transliterate.learn.enabled', true),
                ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

                $nonce = $this->resolveNonce();
                $nonceAttribute = $nonce !== null ? ' nonce="'.e($nonce).'"' : '';

                return '<script'.$nonceAttribute.'>window.fatConfig = '.$json.';</script>';
            },
        );

        if ($this->showToggle) {
            /** @var view-string $toggleView */
            $toggleView = 'filament-auto-transliterate::hooks.toggle';

            $panel->renderHook(
                $this->toggleRenderHook,
                fn (): string => view($toggleView, [
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
