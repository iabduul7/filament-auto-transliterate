<?php

use Iabduul7\FilamentAutoTransliterate\FilamentAutoTransliteratePlugin;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['inputtools.google.com/*' => Http::response(['SUCCESS', [['yeh', ['یہ']]]])]);
});

afterEach(function () {
    FilamentAutoTransliteratePlugin::authorizeUsing(null);
});

function fatUser(int $id): User
{
    return (new User)->forceFill(['id' => $id]);
}

function fatRouteMiddleware(string $name): array
{
    return array_values(array_filter(
        app('router')->getRoutes()->getByName($name)->gatherMiddleware(),
        'is_string',
    ));
}

it('returns 429 once the per-user limit is exceeded', function () {
    config(['filament-auto-transliterate.route.throttle' => '2,1']);
    $this->reloadPackageRoutes();

    $post = fn (User $user) => $this->actingAs($user)
        ->postJson(route('filament-auto-transliterate.translate'), ['text' => 'yeh']);

    $post(fatUser(1))->assertOk();
    $post(fatUser(1))->assertOk();
    $post(fatUser(1))->assertStatus(429);

    // The bucket is per user, so someone else is unaffected.
    $post(fatUser(2))->assertOk();
});

it('registers the named limiter instead of a bare throttle string', function () {
    $middleware = fatRouteMiddleware('filament-auto-transliterate.translate');

    expect($middleware)->toContain('throttle:filament-auto-transliterate')
        ->and($middleware)->not->toContain('throttle:60,1');
});

it('omits throttling when route.throttle is empty', function () {
    config(['filament-auto-transliterate.route.throttle' => '']);
    $this->reloadPackageRoutes();

    expect(collect(fatRouteMiddleware('filament-auto-transliterate.translate'))
        ->filter(fn ($m) => str_starts_with((string) $m, 'throttle:')))->toBeEmpty();

    foreach (range(1, 5) as $_) {
        $this->actingAs(fatUser(1))
            ->postJson(route('filament-auto-transliterate.translate'), ['text' => 'yeh'])
            ->assertOk();
    }
});

it('applies a separate, stricter limit to /learn', function () {
    config([
        'filament-auto-transliterate.route.throttle' => '100,1',
        'filament-auto-transliterate.learn.throttle' => '1,1',
    ]);
    $this->reloadPackageRoutes();

    $learn = fn () => $this->actingAs(fatUser(1))->postJson(route('filament-auto-transliterate.learn'), [
        'original' => 'mkan',
        'corrected' => 'مکان',
    ]);

    $learn()->assertOk();
    $learn()->assertStatus(429);

    // /translate has its own bucket and is unaffected.
    $this->actingAs(fatUser(1))
        ->postJson(route('filament-auto-transliterate.translate'), ['text' => 'yeh'])
        ->assertOk();
});

it('omits the learn throttle when learn.throttle is empty', function () {
    config(['filament-auto-transliterate.learn.throttle' => null]);
    $this->reloadPackageRoutes();

    expect(fatRouteMiddleware('filament-auto-transliterate.learn'))
        ->not->toContain('throttle:filament-auto-transliterate-learn');
});

it('authenticates against the default guard by default', function () {
    expect(fatRouteMiddleware('filament-auto-transliterate.translate'))
        ->toContain('web')->toContain('auth');
});

it('builds the auth middleware from route.guard', function () {
    config([
        'auth.guards.panel' => ['driver' => 'session', 'provider' => 'users'],
        'filament-auto-transliterate.route.guard' => 'panel',
    ]);
    $this->reloadPackageRoutes();

    expect(fatRouteMiddleware('filament-auto-transliterate.translate'))
        ->toContain('auth:panel')->not->toContain('auth');

    // Signed in on the default guard only -> rejected.
    $this->actingAs(fatUser(1))
        ->postJson(route('filament-auto-transliterate.translate'), ['text' => 'yeh'])
        ->assertUnauthorized();

    // Signed in on the configured guard (without making it the default) -> allowed.
    Auth::guard('web')->logout();
    Auth::guard('panel')->setUser(fatUser(1));

    $this->postJson(route('filament-auto-transliterate.translate'), ['text' => 'yeh'])->assertOk();
});

it('honours an explicit route.middleware override verbatim', function () {
    config([
        'filament-auto-transliterate.route.middleware' => ['web'],
        'filament-auto-transliterate.route.guard' => 'panel',
    ]);
    $this->reloadPackageRoutes();

    expect(fatRouteMiddleware('filament-auto-transliterate.translate'))
        ->toContain('web')->not->toContain('auth:panel')->not->toContain('auth');
});

it('returns 403 when the authorize hook denies, and passes when it allows', function () {
    $url = route('filament-auto-transliterate.translate');

    FilamentAutoTransliteratePlugin::authorizeUsing(fn (Request $request) => false);
    $this->actingAs(fatUser(1))->postJson($url, ['text' => 'yeh'])->assertForbidden();

    FilamentAutoTransliteratePlugin::authorizeUsing(fn (Request $request) => $request->user()->getAuthIdentifier() === 1);
    $this->actingAs(fatUser(1))->postJson($url, ['text' => 'yeh'])->assertOk();
    $this->actingAs(fatUser(2))->postJson($url, ['text' => 'yeh'])->assertForbidden();

    FilamentAutoTransliteratePlugin::authorizeUsing(null);
    $this->actingAs(fatUser(2))->postJson($url, ['text' => 'yeh'])->assertOk();
});

it('applies the authorize hook to every endpoint', function () {
    FilamentAutoTransliteratePlugin::authorizeUsing(fn (Request $request) => false);
    $user = fatUser(1);

    $this->actingAs($user)->postJson(route('filament-auto-transliterate.batch'), ['texts' => ['yeh']])->assertForbidden();
    $this->actingAs($user)->postJson(route('filament-auto-transliterate.learn'), ['original' => 'mkan', 'corrected' => 'مکان'])->assertForbidden();
});
