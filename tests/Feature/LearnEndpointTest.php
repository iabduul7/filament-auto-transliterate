<?php

use Iabduul7\FilamentAutoTransliterate\Models\TranslationCache;
use Iabduul7\FilamentAutoTransliterate\Services\TranslationService;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Http;

/*
| POST /learn stores a user's correction of an applied word as ground-truth
| cache row (source=user_correction). See docs/02-self-improvement.md.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(); // any unexpected HTTP call fails the test via preventStrayRequests
});

function learn(array $payload)
{
    return test()->actingAs(new User)
        ->postJson(route('filament-auto-transliterate.learn'), $payload);
}

it('rejects unauthenticated requests', function () {
    $this->postJson(route('filament-auto-transliterate.learn'), [
        'original' => 'mkan',
        'corrected' => 'مکان',
    ])->assertUnauthorized();
});

it('stores a correction and serves it forever after, without hitting a provider', function () {
    learn([
        'original' => 'mkan',
        'corrected' => 'مکان',
        'target_lang' => 'ur',
        'mode' => 'transliterate',
    ])->assertOk()->assertJson(['success' => true]);

    $result = app(TranslationService::class)->translate('mkan', 'ur', 'transliterate');

    expect($result['success'])->toBeTrue()
        ->and($result['translated'])->toBe('مکان')
        ->and($result['source'])->toBe('user_correction');

    // The whole point: a stored correction is a cache hit, never a network call.
    expect(Http::recorded())->toBeEmpty();
});

it('rejects when corrected text is still in Latin script', function () {
    learn([
        'original' => 'mkan',
        'corrected' => 'makan', // not in the target (Urdu) script
        'target_lang' => 'ur',
        'mode' => 'transliterate',
    ])->assertStatus(422);

    expect(TranslationCache::count())->toBe(0);
});

it('rejects when the original text already matches the target script', function () {
    learn([
        'original' => 'مکان', // you can't "correct" something never romanized
        'corrected' => 'مکان',
        'target_lang' => 'ur',
        'mode' => 'transliterate',
    ])->assertStatus(422);
});

it('rejects when corrected equals original', function () {
    learn([
        'original' => 'مکان',
        'corrected' => 'مکان',
        'target_lang' => 'ur',
        'mode' => 'transliterate',
    ])->assertStatus(422);
});

it('rejects an unknown target language', function () {
    learn([
        'original' => 'mkan',
        'corrected' => 'مکان',
        'target_lang' => 'xx',
        'mode' => 'transliterate',
    ])->assertStatus(422);

    expect(TranslationCache::count())->toBe(0);
});

it('returns 404 when learning is disabled', function () {
    config(['filament-auto-transliterate.learn.enabled' => false]);

    learn([
        'original' => 'mkan',
        'corrected' => 'مکان',
        'target_lang' => 'ur',
    ])->assertStatus(404);

    expect(TranslationCache::count())->toBe(0);
});
