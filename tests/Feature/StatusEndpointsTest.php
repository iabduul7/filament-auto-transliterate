<?php

use Iabduul7\FilamentAutoTransliterate\FilamentAutoTransliteratePlugin;
use Iabduul7\FilamentAutoTransliterate\Models\TranslationCache;
use Iabduul7\FilamentAutoTransliterate\Services\TranslationService;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

afterEach(function () {
    FilamentAutoTransliteratePlugin::authorizeUsing(null);
});

it('requires authentication', function () {
    config(['filament-auto-transliterate.ops_endpoints.enabled' => true]);

    $this->getJson(route('filament-auto-transliterate.stats'))->assertUnauthorized();
    $this->getJson(route('filament-auto-transliterate.status'))->assertUnauthorized();
});

it('hides the ops endpoints (404) unless enabled', function () {
    $this->actingAs(new User)->getJson(route('filament-auto-transliterate.stats'))->assertNotFound();
    $this->actingAs(new User)->getJson(route('filament-auto-transliterate.status'))->assertNotFound();
});

it('returns 403 for the ops endpoints when the authorize hook denies', function () {
    config(['filament-auto-transliterate.ops_endpoints.enabled' => true]);
    FilamentAutoTransliteratePlugin::authorizeUsing(fn (Request $request) => false);

    $this->actingAs(new User)->getJson(route('filament-auto-transliterate.stats'))->assertForbidden();
    $this->actingAs(new User)->getJson(route('filament-auto-transliterate.status'))->assertForbidden();
});

it('returns cache stats when enabled', function () {
    config(['filament-auto-transliterate.ops_endpoints.enabled' => true]);
    TranslationCache::cacheTranslation('mkan', 'مکان', 'ur', 'google_input_tools', 0.9, 0.0, 'transliterate');

    $this->actingAs(new User)->getJson(route('filament-auto-transliterate.stats'))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('stats.total_translations', 1)
        ->assertJsonStructure(['stats' => ['total_translations', 'by_source', 'avg_confidence', 'avg_processing_time'], 'timestamp']);
});

it('reports configured + breaker state without calling any provider by default', function () {
    config(['filament-auto-transliterate.ops_endpoints.enabled' => true]);

    $response = $this->actingAs(new User)->getJson(route('filament-auto-transliterate.status'))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('probed', false)
        ->assertJsonStructure(['providers' => ['google_input_tools' => ['configured', 'circuit_open']]]);

    expect($response->json('providers.google_input_tools'))->not->toHaveKeys(['ok', 'error'])
        ->and($response->json('providers.microsoft.configured'))->toBeFalse();

    Http::assertNothingSent();
});

it('probes providers only when explicitly enabled, and never leaks exception text', function () {
    config([
        'filament-auto-transliterate.ops_endpoints.enabled' => true,
        'filament-auto-transliterate.ops_endpoints.probe_providers' => true,
    ]);
    Http::fake(['*' => Http::response('SECRET-UPSTREAM-DETAIL api_key=hunter2', 500)]);

    $response = $this->actingAs(new User)->getJson(route('filament-auto-transliterate.status'))
        ->assertOk()
        ->assertJsonPath('probed', true)
        ->assertJsonPath('providers.google_input_tools.ok', false)
        ->assertJsonPath('providers.google_input_tools.error', 'unavailable');

    expect($response->getContent())->not->toContain('SECRET-UPSTREAM-DETAIL')->not->toContain('hunter2');
});

it('does not leak exception text when the status endpoint itself fails', function () {
    config([
        'filament-auto-transliterate.ops_endpoints.enabled' => true,
        'app.debug' => false,
    ]);

    app()->instance(
        TranslationService::class,
        new class extends TranslationService
        {
            public function providerStatus(): array
            {
                throw new RuntimeException('SECRET-INTERNAL-DETAIL');
            }
        },
    );
    config(['filament-auto-transliterate.ops_endpoints.probe_providers' => true]);

    $response = $this->actingAs(new User)->getJson(route('filament-auto-transliterate.status'))
        ->assertStatus(500)
        ->assertJsonPath('error', 'Internal server error');

    expect($response->getContent())->not->toContain('SECRET-INTERNAL-DETAIL');
});
