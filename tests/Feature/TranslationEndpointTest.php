<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('rejects unauthenticated requests', function () {
    $this->postJson(route('filament-auto-translate.translate'), ['text' => 'yeh'])
        ->assertUnauthorized();
});

it('allows authenticated requests', function () {
    Http::fake([
        'inputtools.google.com/*' => Http::response(['SUCCESS', [['yeh', ['یہ']]]]),
    ]);

    $this->actingAs(new User)
        ->postJson(route('filament-auto-translate.translate'), [
            'text' => 'yeh',
            'mode' => 'transliterate',
        ])
        ->assertOk()
        ->assertJson(['success' => true, 'translated' => 'یہ', 'source' => 'google_input_tools']);
});

it('validates that text is required', function () {
    $this->actingAs(new User)
        ->postJson(route('filament-auto-translate.translate'), [])
        ->assertStatus(422);
});

it('rejects an unknown mode', function () {
    $this->actingAs(new User)
        ->postJson(route('filament-auto-translate.translate'), [
            'text' => 'yeh',
            'mode' => 'bogus',
        ])
        ->assertStatus(422);
});

it('does not expose the routes under an api prefix', function () {
    expect(route('filament-auto-translate.translate'))
        ->not->toContain('/api/')
        ->toContain('/filament-auto-translate/translate');
});
