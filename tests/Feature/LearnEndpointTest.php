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

it('rejects a correction that mixes in letters of another script', function () {
    learn(['original' => 'mkan', 'corrected' => 'مکانabc', 'target_lang' => 'ur'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('corrected');

    expect(TranslationCache::count())->toBe(0);
});

it('rejects a correction with no letters at all', function () {
    learn(['original' => 'mkan', 'corrected' => '123 !!', 'target_lang' => 'ur'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('corrected');
});

it('ignores digits, punctuation and whitespace when checking the script', function () {
    learn(['original' => 'mkan 2', 'corrected' => 'مکان 2!', 'target_lang' => 'ur'])->assertOk();
});

it('can allow mixed-script corrections by lowering learn.min_script_ratio', function () {
    config(['filament-auto-transliterate.learn.min_script_ratio' => 0.5]);

    learn(['original' => 'mkan', 'corrected' => 'مکانab', 'target_lang' => 'ur'])->assertOk();
});

it('rejects an original longer than learn.max_original_length', function () {
    config(['filament-auto-transliterate.learn.max_original_length' => 5]);

    learn(['original' => 'abcdefgh', 'corrected' => 'مکان', 'target_lang' => 'ur'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('original');
});

it('rejects a mode that is not in allowed_modes', function () {
    config(['filament-auto-transliterate.allowed_modes' => ['transliterate']]);

    learn(['original' => 'mkan', 'corrected' => 'مکان', 'mode' => 'translate'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('mode');
});

it('treats an empty-string target_script_pattern as unset, like the service does', function () {
    config(['filament-auto-transliterate.target_script_pattern' => '']);

    learn(['original' => 'mkan', 'corrected' => 'مکان', 'target_lang' => 'ur'])->assertOk();
    learn(['original' => 'mkan2', 'corrected' => 'makan2', 'target_lang' => 'ur'])->assertStatus(422);
});

it('stores the id of the correcting user', function () {
    $user = (new User)->forceFill(['id' => 42]);

    test()->actingAs($user)->postJson(route('filament-auto-transliterate.learn'), [
        'original' => 'mkan',
        'corrected' => 'مکان',
        'target_lang' => 'ur',
        'mode' => 'transliterate',
    ])->assertOk();

    $row = TranslationCache::getTranslation('mkan', 'ur', 'transliterate');

    expect($row->source)->toBe('user_correction')
        ->and($row->user_id)->toBe(42);
});

it('replaces an older correction and updates who made it', function () {
    foreach ([[7, 'مکن'], [9, 'مکان']] as [$id, $corrected]) {
        test()->actingAs((new User)->forceFill(['id' => $id]))
            ->postJson(route('filament-auto-transliterate.learn'), [
                'original' => 'mkan',
                'corrected' => $corrected,
                'target_lang' => 'ur',
                'mode' => 'transliterate',
            ])->assertOk();
    }

    $row = TranslationCache::getTranslation('mkan', 'ur', 'transliterate');

    expect(TranslationCache::count())->toBe(1)
        ->and($row->translated_text)->toBe('مکان')
        ->and($row->user_id)->toBe(9);
});

it('leaves user_id null when the user has no numeric id', function () {
    learn(['original' => 'mkan', 'corrected' => 'مکان', 'target_lang' => 'ur'])->assertOk();

    expect(TranslationCache::getTranslation('mkan', 'ur', 'transliterate')->user_id)->toBeNull();
});
