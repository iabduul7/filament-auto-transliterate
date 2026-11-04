<?php

use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Iabduul7\FilamentAutoTransliterate\FilamentAutoTransliteratePlugin;
use Illuminate\Support\Facades\Vite;

function fatPanel(?Closure $configure = null): Panel
{
    $plugin = FilamentAutoTransliteratePlugin::make();

    if ($configure) {
        $configure($plugin);
    }

    return Panel::make()->id('fat-test')->plugin($plugin);
}

function fatRenderHook(Panel $panel, string $hook): string
{
    $hooks = (new ReflectionProperty($panel, 'renderHooks'))->getValue($panel)[$hook][''] ?? [];

    return implode('', array_map(fn (Closure $fn) => (string) $fn(), $hooks));
}

function fatHookNames(Panel $panel): array
{
    return array_keys((new ReflectionProperty($panel, 'renderHooks'))->getValue($panel));
}

it('emits window.fatConfig with HTML-significant characters escaped', function () {
    config(['filament-auto-transliterate.languages.ur.label' => '</script><script>alert(1)</script> & \'x\' "y"']);

    $html = fatRenderHook(fatPanel(), PanelsRenderHook::HEAD_END);

    expect($html)->toContain('window.fatConfig = ')
        ->not->toContain('</script><script>alert(1)')
        ->toContain('\\u00'.'3C\\/script\\u00'.'3E')
        ->toContain('\\u00'.'26')
        ->toContain('\\u00'.'27')
        ->toContain('\\u00'.'22');

    // Still valid JSON that round-trips to the original label.
    preg_match('/window\.fatConfig = (.*);<\/script>/s', $html, $m);
    expect(json_decode($m[1], true)['languages']['ur']['label'])->toBe('</script><script>alert(1)</script> & \'x\' "y"');
});

it('emits no nonce attribute by default', function () {
    expect(fatRenderHook(fatPanel(), PanelsRenderHook::HEAD_END))->toContain('<script>window.fatConfig');
});

it('adds a nonce from the Vite CSP nonce Filament also uses', function () {
    Vite::useCspNonce('vite-nonce');

    expect(fatRenderHook(fatPanel(), PanelsRenderHook::HEAD_END))->toContain('<script nonce="vite-nonce">window.fatConfig');
});

it('lets the plugin override the nonce with a string or a closure', function () {
    Vite::useCspNonce('vite-nonce');

    $string = fatPanel(fn ($plugin) => $plugin->scriptNonce('abc"123'));
    $closure = fatPanel(fn ($plugin) => $plugin->scriptNonce(fn () => 'from-closure'));

    expect(fatRenderHook($string, PanelsRenderHook::HEAD_END))->toContain('nonce="abc&quot;123"')
        ->and(fatRenderHook($closure, PanelsRenderHook::HEAD_END))->toContain('nonce="from-closure"');
});

it('renders the toggle at GLOBAL_SEARCH_BEFORE by default', function () {
    $panel = fatPanel();

    expect(fatHookNames($panel))->toContain(PanelsRenderHook::GLOBAL_SEARCH_BEFORE)
        ->and(fatRenderHook($panel, PanelsRenderHook::GLOBAL_SEARCH_BEFORE))->toContain('FilamentAutoTransliterate');
});

it('moves the toggle to a custom render hook', function () {
    $panel = fatPanel(fn ($plugin) => $plugin->toggleRenderHook(PanelsRenderHook::USER_MENU_BEFORE));

    expect(fatHookNames($panel))->toContain(PanelsRenderHook::USER_MENU_BEFORE)
        ->not->toContain(PanelsRenderHook::GLOBAL_SEARCH_BEFORE)
        ->and(fatRenderHook($panel, PanelsRenderHook::USER_MENU_BEFORE))->toContain('FilamentAutoTransliterate');
});

it('omits the toggle hook entirely when the toggle is hidden', function () {
    $panel = fatPanel(fn ($plugin) => $plugin->showToggle(false));

    expect(fatHookNames($panel))->not->toContain(PanelsRenderHook::GLOBAL_SEARCH_BEFORE);
});

it('resolves each configured provider as a singleton', function () {
    foreach (config('filament-auto-transliterate.provider_map') as $class) {
        expect(app($class))->toBe(app($class));
    }
});
