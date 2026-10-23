<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function fatBatch(array $payload)
{
    return test()->actingAs(new User)
        ->postJson(route('filament-auto-transliterate.batch'), $payload);
}

it('rejects unauthenticated batch requests', function () {
    $this->postJson(route('filament-auto-transliterate.batch'), ['texts' => ['yeh']])
        ->assertUnauthorized();
});

it('rejects more texts than max_batch_size', function () {
    config(['filament-auto-transliterate.max_batch_size' => 2]);

    fatBatch(['texts' => ['a1', 'b2', 'c3']])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonValidationErrors('texts');
});

it('requires texts to be a non-empty array of strings', function () {
    fatBatch([])->assertStatus(422);
    fatBatch(['texts' => 'yeh'])->assertStatus(422);
    fatBatch(['texts' => [['nested']]])->assertStatus(422);
});

it('rejects an over-long item', function () {
    config(['filament-auto-transliterate.max_text_length' => 5]);

    fatBatch(['texts' => ['ok', 'far too long']])->assertStatus(422);
});

it('rejects an unknown target_lang', function () {
    fatBatch(['texts' => ['yeh'], 'target_lang' => 'xx'])->assertStatus(422);
});

it('rejects a mode that is not in allowed_modes', function () {
    config(['filament-auto-transliterate.allowed_modes' => ['transliterate']]);

    fatBatch(['texts' => ['help'], 'mode' => 'translate'])->assertStatus(422)->assertJsonValidationErrors('mode');
});

it('returns per-item results keyed by input index, with success true on a partial batch', function () {
    Http::fakeSequence('inputtools.google.com/*')
        ->push(['SUCCESS', [['yeh', ['یہ']]]])
        ->push('boom', 500);

    $response = fatBatch(['texts' => ['yeh', 'zzqq'], 'mode' => 'transliterate'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('total', 2)
        ->assertJsonPath('successful', 1)
        ->assertJsonPath('results.0.success', true)
        ->assertJsonPath('results.0.translated', 'یہ')
        ->assertJsonPath('results.1.success', false)
        ->assertJsonPath('results.1.original', 'zzqq');

    expect($response->json('results'))->toHaveCount(2);
});

it('reports success false when every item fails', function () {
    Http::fake(['inputtools.google.com/*' => Http::response('boom', 500)]);

    fatBatch(['texts' => ['zzqq', 'wwxx'], 'mode' => 'transliterate'])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('total', 2)
        ->assertJsonPath('successful', 0);
});

it('treats already-target-script items as unsuccessful no-ops', function () {
    fatBatch(['texts' => ['یہ']])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('results.0.message', 'No translation needed');
});
