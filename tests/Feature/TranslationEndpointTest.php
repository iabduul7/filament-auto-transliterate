<?php

use Iabduul7\FilamentAutoTransliterate\Enums\TranslationMode;
use Iabduul7\FilamentAutoTransliterate\Services\TranslationService;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('rejects unauthenticated requests', function () {
    $this->postJson(route('filament-auto-transliterate.translate'), ['text' => 'yeh'])
        ->assertUnauthorized();
});

it('allows authenticated requests', function () {
    Http::fake([
        'inputtools.google.com/*' => Http::response(['SUCCESS', [['yeh', ['یہ']]]]),
    ]);

    $this->actingAs(new User)
        ->postJson(route('filament-auto-transliterate.translate'), [
            'text' => 'yeh',
            'mode' => 'transliterate',
        ])
        ->assertOk()
        ->assertJson(['success' => true, 'translated' => 'یہ', 'source' => 'google_input_tools']);
});

it('validates that text is required', function () {
    $this->actingAs(new User)
        ->postJson(route('filament-auto-transliterate.translate'), [])
        ->assertStatus(422);
});

it('rejects an unknown mode', function () {
    $this->actingAs(new User)
        ->postJson(route('filament-auto-transliterate.translate'), [
            'text' => 'yeh',
            'mode' => 'bogus',
        ])
        ->assertStatus(422);
});

it('does not expose the routes under an api prefix', function () {
    expect(route('filament-auto-transliterate.translate'))
        ->not->toContain('/api/')
        ->toContain('/filament-auto-transliterate/translate');
});

function fatTranslate(array $payload)
{
    return test()->actingAs(new User)
        ->postJson(route('filament-auto-transliterate.translate'), $payload);
}

it('rejects an unknown target_lang with 422', function () {
    fatTranslate(['text' => 'yeh', 'target_lang' => 'xx'])->assertStatus(422)->assertJsonValidationErrors('target_lang');
});

it('rejects an over-long target_lang', function () {
    fatTranslate(['text' => 'yeh', 'target_lang' => str_repeat('u', 11)])->assertStatus(422);
});

it('rejects text longer than max_text_length', function () {
    config(['filament-auto-transliterate.max_text_length' => 5]);

    fatTranslate(['text' => 'abcdef'])->assertStatus(422)->assertJsonValidationErrors('text');
});

it('rejects a mode that is not in allowed_modes', function () {
    config(['filament-auto-transliterate.allowed_modes' => ['transliterate']]);

    fatTranslate(['text' => 'help', 'mode' => 'translate'])->assertStatus(422)->assertJsonValidationErrors('mode');
});

it('does not let an omitted mode sidestep allowed_modes', function () {
    config([
        'filament-auto-transliterate.allowed_modes' => ['transliterate'],
        'filament-auto-transliterate.mode' => 'translate',
    ]);

    fatTranslate(['text' => 'help'])->assertStatus(422)->assertJsonValidationErrors('mode');
});

it('still accepts an allowed mode when the list is restricted', function () {
    config(['filament-auto-transliterate.allowed_modes' => ['transliterate']]);
    Http::fake(['inputtools.google.com/*' => Http::response(['SUCCESS', [['yeh', ['یہ']]]])]);

    fatTranslate(['text' => 'yeh', 'mode' => 'transliterate'])->assertOk()->assertJson(['success' => true]);
});

function fatBrokenService(): void
{
    app()->instance(
        TranslationService::class,
        new class extends TranslationService
        {
            public function translate(string $text, ?string $targetLang = null, TranslationMode|string|null $mode = null): array
            {
                throw new RuntimeException('SECRET-INTERNAL-DETAIL');
            }
        },
    );
}

it('hides the exception message on a 500 unless app.debug is on, and reports it', function () {
    Exceptions::fake();
    config(['app.debug' => false]);
    fatBrokenService();

    $response = fatTranslate(['text' => 'yeh'])
        ->assertStatus(500)
        ->assertJson(['success' => false, 'message' => 'Translation failed', 'error' => 'Internal server error']);

    expect($response->getContent())->not->toContain('SECRET-INTERNAL-DETAIL');

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'SECRET-INTERNAL-DETAIL');
});

it('exposes the exception message on a 500 when app.debug is on', function () {
    Exceptions::fake();
    config(['app.debug' => true]);
    fatBrokenService();

    fatTranslate(['text' => 'yeh'])->assertStatus(500)->assertJsonPath('error', 'SECRET-INTERNAL-DETAIL'
    );
});

it('passes the transient flag through for a provider outage but not for a genuine miss', function () {
    config(['filament-auto-transliterate.providers.transliterate' => ['google_input_tools']]);
    Http::fake(['inputtools.google.com/*' => Http::response('error', 500)]);

    $this->actingAs(new User)
        ->postJson(route('filament-auto-transliterate.translate'), ['text' => 'zzqq', 'mode' => 'transliterate'])
        ->assertOk()
        ->assertJson(['success' => false, 'transient' => true]);

    $this->actingAs(new User)
        ->postJson(route('filament-auto-transliterate.batch'), ['texts' => ['zzqq'], 'mode' => 'transliterate'])
        ->assertOk()
        ->assertJsonPath('results.0.transient', true);

    // No providers configured: nothing errored, so it is a plain miss.
    config(['filament-auto-transliterate.providers.transliterate' => []]);

    $response = $this->actingAs(new User)
        ->postJson(route('filament-auto-transliterate.translate'), ['text' => 'zzqq', 'mode' => 'transliterate'])
        ->assertOk();

    expect($response->json())->not->toHaveKey('transient');
});
