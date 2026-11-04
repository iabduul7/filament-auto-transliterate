<?php

use Iabduul7\FilamentAutoTransliterate\Contracts\TranslationProvider;
use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;
use Iabduul7\FilamentAutoTransliterate\Enums\TranslationMode;
use Iabduul7\FilamentAutoTransliterate\Exceptions\SanitizedException;
use Iabduul7\FilamentAutoTransliterate\Models\TranslationCache;
use Iabduul7\FilamentAutoTransliterate\Services\TranslationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** A provider that always throws, to exercise the service's exception path. */
class FatThrowingProvider implements TranslationProvider
{
    public function key(): string
    {
        return 'throwing';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function translate(string $text, string $sourceLang, string $targetLang): TranslationResult
    {
        throw new RuntimeException("secret user text: {$text}");
    }
}

beforeEach(function () {
    Http::preventStrayRequests();
});

function service(): TranslationService
{
    return app(TranslationService::class);
}

function fatCircuitState(string $key): mixed
{
    return Cache::get("fat_provider_cb_{$key}");
}

function fatTempDictionary(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'fatdict');
    file_put_contents($path, json_encode($entries, JSON_UNESCAPED_UNICODE));

    return $path;
}

it('leaves text untouched when it is already in the target script', function () {
    $result = service()->translate('یہ', 'ur');

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('No translation needed')
        ->and($result['original'])->toBe('یہ');
});

it('transliterates via google input tools in transliterate mode', function () {
    Http::fake([
        'inputtools.google.com/*' => Http::response(['SUCCESS', [['yeh', ['یہ']]]]),
    ]);

    $result = service()->translate('yeh', 'ur', 'transliterate');

    expect($result['success'])->toBeTrue()
        ->and($result['source'])->toBe('google_input_tools')
        ->and($result['translated'])->toBe('یہ');
});

it('never falls through to a meaning provider when transliteration misses', function () {
    Http::fake([
        'inputtools.google.com/*' => Http::response('error', 500),
        '*' => Http::response(['responseStatus' => 200, 'responseData' => ['translatedText' => 'LEAK']]),
    ]);

    $result = service()->translate('zzqq', 'ur', 'transliterate');

    // The whole point of the package: a transliterate miss leaves the text
    // unchanged and must NOT reach a translation provider.
    expect($result['success'])->toBeFalse()
        ->and($result['original'])->toBe('zzqq');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'mymemory'));
});

it('keeps the transliterate and translate provider chains disjoint', function () {
    $translit = array_map(
        fn (TranslationProvider $p) => $p->key(),
        service()->providersForMode(TranslationMode::Transliterate),
    );
    $translate = array_map(
        fn (TranslationProvider $p) => $p->key(),
        service()->providersForMode(TranslationMode::Translate),
    );

    expect($translit)->not->toBeEmpty()
        ->and($translate)->not->toBeEmpty()
        ->and(array_intersect($translit, $translate))->toBe([])
        ->and($translit)->not->toContain('mymemory', 'libretranslate', 'microsoft', 'google', 'dictionary');
});

it('translates by meaning via mymemory in translate mode', function () {
    Http::fake([
        'api.mymemory.translated.net/*' => Http::response([
            'responseStatus' => 200,
            'responseData' => ['translatedText' => 'مدد', 'match' => 0.9],
        ]),
    ]);

    $result = service()->translate('help', 'ur', 'translate');

    expect($result['success'])->toBeTrue()
        ->and($result['source'])->toBe('mymemory')
        ->and($result['translated'])->toBe('مدد');
});

it('serves a cached result without re-hitting providers', function () {
    Http::fake([
        'inputtools.google.com/*' => Http::response(['SUCCESS', [['yeh', ['یہ']]]]),
    ]);

    service()->translate('yeh', 'ur', 'transliterate');
    $afterFirst = count(Http::recorded());

    $second = service()->translate('yeh', 'ur', 'transliterate');

    expect(count(Http::recorded()))->toBe($afterFirst)
        ->and($second['success'])->toBeTrue()
        ->and($second['source'])->toBe('google_input_tools');
});

it('caches transliterate and translate of the same word separately', function () {
    Http::fake([
        'inputtools.google.com/*' => Http::response(['SUCCESS', [['school', ['سکول']]]]),
        'api.mymemory.translated.net/*' => Http::response([
            'responseStatus' => 200,
            'responseData' => ['translatedText' => 'مدرسہ', 'match' => 0.9],
        ]),
    ]);

    $translit = service()->translate('school', 'ur', 'transliterate');
    $translate = service()->translate('school', 'ur', 'translate');

    expect($translit['translated'])->toBe('سکول')
        ->and($translate['translated'])->toBe('مدرسہ');
});

it('does not transliterate on total failure when the char fallback is disabled', function () {
    config(['filament-auto-transliterate.fallback_transliteration' => false]);
    Http::fake(['*' => Http::response('error', 500)]);

    $result = service()->translate('zzqq', 'ur', 'translate');

    expect($result['success'])->toBeFalse()
        ->and($result)->not->toHaveKey('translated_text');
    expect($result['translated'])->toBeNull();
});

it('uses the char fallback only when explicitly enabled', function () {
    config(['filament-auto-transliterate.fallback_transliteration' => true]);
    Http::fake(['*' => Http::response('error', 500)]);

    $result = service()->translate('zzqq', 'ur', 'transliterate');

    expect($result['success'])->toBeTrue()
        ->and($result['source'])->toBe('transliteration');
});

it('never caches the char fallback result', function () {
    config(['filament-auto-transliterate.fallback_transliteration' => true]);
    Http::fake(['inputtools.google.com/*' => Http::sequence()
        ->pushStatus(500)
        ->push(['SUCCESS', [['zzqq', ['زززق']]]])]);

    $first = service()->translate('zzqq', 'ur', 'transliterate');

    expect($first['source'])->toBe('transliteration')
        ->and(TranslationCache::query()->count())->toBe(0);

    // A recovered provider must be able to answer the same text afterwards.
    $second = service()->translate('zzqq', 'ur', 'transliterate');

    expect($second['source'])->toBe('google_input_tools')
        ->and($second['translated'])->toBe('زززق');
});

it('does not apply the char fallback in translate mode', function () {
    config(['filament-auto-transliterate.fallback_transliteration' => true]);
    Http::fake(['*' => Http::response('error', 500)]);

    $result = service()->translate('zzqq', 'ur', 'translate');

    expect($result['success'])->toBeFalse()
        ->and($result['original'])->toBe('zzqq');
});

it('applies a legacy flat char map only to the configured default target', function () {
    config([
        'filament-auto-transliterate.fallback_transliteration' => true,
        'filament-auto-transliterate.target_language' => 'ur',
        'filament-auto-transliterate.char_fallback_map' => ['z' => 'ز', 'q' => 'ق'],
    ]);
    Http::fake(['*' => Http::response('error', 500)]);

    $ur = service()->translate('zzqq', 'ur', 'transliterate');
    $hi = service()->translate('zzqq', 'hi', 'transliterate');

    expect($ur['success'])->toBeTrue()
        ->and($ur['translated'])->toBe('ززقق')
        // Urdu letters must never leak into a Hindi field.
        ->and($hi['success'])->toBeFalse()
        ->and($hi['original'])->toBe('zzqq');
});

it('picks the char map by target language when the map is keyed by code', function () {
    config([
        'filament-auto-transliterate.fallback_transliteration' => true,
        'filament-auto-transliterate.char_fallback_map' => [
            'ur' => ['z' => 'ز', 'q' => 'ق'],
            'hi' => ['z' => 'ज़', 'q' => 'क'],
        ],
    ]);
    Http::fake(['*' => Http::response('error', 500)]);

    $ur = service()->translate('zq', 'ur', 'transliterate');
    $hi = service()->translate('zq', 'hi', 'transliterate');
    $ru = service()->translate('zq', 'ru', 'transliterate');

    expect($ur['translated'])->toBe('زق')
        ->and($hi['translated'])->toBe('ज़क')
        ->and($ru['success'])->toBeFalse();
});

it('returns a noop for text shorter than min_text_length without hitting providers', function () {
    config(['filament-auto-transliterate.min_text_length' => 3]);

    $result = service()->translate('ab', 'ur', 'transliterate');

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Text too short')
        ->and($result['original'])->toBe('ab');

    Http::assertNothingSent();
});

it('falls back to the configured default target for an unknown language code', function () {
    config(['filament-auto-transliterate.target_language' => 'ur']);
    Http::fake([
        'inputtools.google.com/*' => Http::response(['SUCCESS', [['yeh', ['یہ']]]]),
    ]);

    service()->translate('yeh', 'not-a-real-language', 'transliterate');

    // Defense-in-depth: Languages::resolve() maps the garbage code to the
    // configured default before it ever reaches a provider.
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'itc=ur-t-i0-und'));
});

it('detects Devanagari input as already in the target script for Hindi', function () {
    $result = service()->translate('हिन्दी', 'hi', 'transliterate');

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('No translation needed');

    Http::assertNothingSent();
});

it('opens the circuit breaker after consecutive failures and skips the provider until it resets', function () {
    config([
        'filament-auto-transliterate.provider_failure_threshold' => 3,
        'filament-auto-transliterate.provider_failure_cooldown' => 120,
        // Isolate the transliterate chain to just the network provider so we
        // can count its hits precisely.
        'filament-auto-transliterate.providers.transliterate' => ['google_input_tools'],
    ]);
    Http::fake(['inputtools.google.com/*' => Http::response('error', 500)]);

    // Three distinct words so the cache never short-circuits the attempt.
    service()->translate('wordone', 'ur', 'transliterate');
    service()->translate('wordtwo', 'ur', 'transliterate');
    service()->translate('wordthree', 'ur', 'transliterate');

    expect(Http::recorded())->toHaveCount(3);

    // The breaker is now open: a fourth distinct word must not hit the network.
    service()->translate('wordfour', 'ur', 'transliterate');

    expect(Http::recorded())->toHaveCount(3);
});

it('does not open the breaker after many dictionary misses', function () {
    $path = fatTempDictionary(['known' => 'معلوم']);
    config([
        'filament-auto-transliterate.provider_failure_threshold' => 3,
        'filament-auto-transliterate.dictionary_path' => $path,
        'filament-auto-transliterate.providers.translate' => ['dictionary', 'mymemory'],
    ]);
    Http::fake([
        'api.mymemory.translated.net/*' => Http::response([
            'responseStatus' => 200,
            'responseData' => ['translatedText' => 'کچھ', 'match' => 0.9],
        ]),
    ]);

    foreach (['alpha', 'bravo', 'charlie', 'delta', 'echo'] as $word) {
        $result = service()->translate($word, 'ur', 'translate');
        expect($result['source'])->toBe('mymemory');
    }

    // Five misses (threshold is 3) and the dictionary breaker was never touched.
    expect(fatCircuitState('dictionary'))->toBeNull();

    $hit = service()->translate('known', 'ur', 'translate');
    expect($hit['source'])->toBe('dictionary');

    @unlink($path);
});

it('does not log a warning for a provider miss', function () {
    $path = fatTempDictionary(['known' => 'معلوم']);
    config([
        'filament-auto-transliterate.dictionary_path' => $path,
        'filament-auto-transliterate.providers.translate' => ['dictionary'],
    ]);
    Log::spy();

    $result = service()->translate('unknownword', 'ur', 'translate');

    expect($result['success'])->toBeFalse();
    Log::shouldNotHaveReceived('warning');

    @unlink($path);
});

it('opens the breaker after consecutive transport errors', function () {
    config([
        'filament-auto-transliterate.provider_failure_threshold' => 3,
        'filament-auto-transliterate.provider_failure_cooldown' => 120,
        'filament-auto-transliterate.providers.transliterate' => ['google_input_tools'],
    ]);
    Http::fake(['inputtools.google.com/*' => Http::failedConnection()]);

    service()->translate('wordone', 'ur', 'transliterate');
    service()->translate('wordtwo', 'ur', 'transliterate');
    service()->translate('wordthree', 'ur', 'transliterate');

    expect(Http::recorded())->toHaveCount(3)
        ->and(fatCircuitState('google_input_tools')['open_until'])->toBeGreaterThan(now()->getTimestamp());

    service()->translate('wordfour', 'ur', 'transliterate');

    expect(Http::recorded())->toHaveCount(3);
});

it('trips the breaker on 429 responses', function () {
    config([
        'filament-auto-transliterate.provider_failure_threshold' => 2,
        'filament-auto-transliterate.providers.transliterate' => ['google_input_tools'],
    ]);
    Http::fake(['inputtools.google.com/*' => Http::response('slow down', 429)]);

    service()->translate('wordone', 'ur', 'transliterate');
    service()->translate('wordtwo', 'ur', 'transliterate');
    service()->translate('wordthree', 'ur', 'transliterate');

    expect(Http::recorded())->toHaveCount(2);
});

it('resets the failure counter after a success', function () {
    config([
        'filament-auto-transliterate.provider_failure_threshold' => 3,
        'filament-auto-transliterate.providers.transliterate' => ['google_input_tools'],
    ]);
    $ok = ['SUCCESS', [['w', ['و']]]];
    Http::fake([
        'inputtools.google.com/*' => Http::sequence()
            ->pushStatus(500)
            ->pushStatus(500)
            ->push($ok)
            ->pushStatus(500)
            ->pushStatus(500)
            ->push($ok),
    ]);

    foreach (['wordone', 'wordtwo', 'wordthree', 'wordfour', 'wordfive', 'wordsix'] as $word) {
        service()->translate($word, 'ur', 'transliterate');
    }

    // Without the reset, errors #1, #2 and #4 would have opened the breaker and
    // the 5th/6th requests would never have been sent.
    expect(Http::recorded())->toHaveCount(6)
        ->and(fatCircuitState('google_input_tools'))->toBeNull();
});

it('closes the breaker again once the cooldown has expired', function () {
    config([
        'filament-auto-transliterate.provider_failure_threshold' => 2,
        'filament-auto-transliterate.provider_failure_cooldown' => 60,
        'filament-auto-transliterate.providers.transliterate' => ['google_input_tools'],
    ]);
    Http::fake(['inputtools.google.com/*' => Http::response('error', 500)]);

    service()->translate('wordone', 'ur', 'transliterate');
    service()->translate('wordtwo', 'ur', 'transliterate');
    service()->translate('wordthree', 'ur', 'transliterate'); // skipped: open

    expect(Http::recorded())->toHaveCount(2);

    $this->travelTo(now()->addSeconds(61));

    service()->translate('wordfour', 'ur', 'transliterate'); // cooldown over: retried

    expect(Http::recorded())->toHaveCount(3);
});

it('keeps one breaker per provider', function () {
    $path = fatTempDictionary(['known' => 'معلوم']);
    config([
        'filament-auto-transliterate.provider_failure_threshold' => 2,
        'filament-auto-transliterate.dictionary_path' => $path,
        'filament-auto-transliterate.providers.translate' => ['mymemory', 'dictionary'],
    ]);
    Http::fake(['api.mymemory.translated.net/*' => Http::response('error', 500)]);

    service()->translate('wordone', 'ur', 'translate');
    service()->translate('wordtwo', 'ur', 'translate');

    // mymemory is open; the dictionary behind it still answers.
    $hit = service()->translate('known', 'ur', 'translate');

    expect($hit['source'])->toBe('dictionary')
        ->and(fatCircuitState('mymemory'))->not->toBeNull()
        ->and(fatCircuitState('dictionary'))->toBeNull();

    @unlink($path);
});

it('logs only the exception class and reports it when log_requests is off', function () {
    config([
        'filament-auto-transliterate.log_requests' => false,
        'filament-auto-transliterate.provider_map.throwing' => FatThrowingProvider::class,
        'filament-auto-transliterate.providers.transliterate' => ['throwing'],
    ]);
    Log::spy();

    $result = service()->translate('verysecret', 'ur', 'transliterate');

    expect($result['success'])->toBeFalse();
    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => str_contains($message, '[FilamentAutoTransliterate]')
            && str_contains($message, 'RuntimeException (see report())')
            && ! str_contains($message, 'verysecret'))
        ->once();
});

it('includes the exception message in the log when log_requests is on', function () {
    config([
        'filament-auto-transliterate.log_requests' => true,
        'filament-auto-transliterate.provider_map.throwing' => FatThrowingProvider::class,
        'filament-auto-transliterate.providers.transliterate' => ['throwing'],
    ]);
    Log::spy();

    service()->translate('verysecret', 'ur', 'transliterate');

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => str_contains($message, 'RuntimeException: secret user text: verysecret'))
        ->once();
});

it('treats a thrown exception as a breaker-counted error without leaking its message', function () {
    config([
        'filament-auto-transliterate.provider_failure_threshold' => 1,
        'filament-auto-transliterate.provider_map.throwing' => FatThrowingProvider::class,
        'filament-auto-transliterate.providers.transliterate' => ['throwing'],
    ]);

    service()->translate('verysecret', 'ur', 'transliterate');

    expect(fatCircuitState('throwing')['open_until'])->toBeGreaterThan(now()->getTimestamp());
});

it('passes alternatives through the response array', function () {
    Http::fake([
        'inputtools.google.com/*' => Http::response([
            'SUCCESS',
            [['yeh', ['یہ', 'یے', 'یہہ']]],
        ]),
    ]);

    $result = service()->translate('yeh', 'ur', 'transliterate');

    expect($result['success'])->toBeTrue()
        ->and($result['translated'])->toBe('یہ')
        ->and($result['alternatives'])->toBe(['یے', 'یہہ']);
});

it('keeps google input tools segments without candidates as the source token', function () {
    Http::fake([
        'inputtools.google.com/*' => Http::response([
            'SUCCESS',
            [['yeh', ['یہ']], ['xyzzy', []], ['aaj', ['آج']]],
        ]),
    ]);

    $result = service()->translate('yeh xyzzy aaj', 'ur', 'transliterate');

    expect($result['success'])->toBeTrue()
        ->and($result['translated'])->toBe('یہ xyzzy آج');
});

it('reports a sanitized exception and never logs the message when log_requests is off', function () {
    Exceptions::fake();
    config([
        'filament-auto-transliterate.log_requests' => false,
        'filament-auto-transliterate.provider_map.throwing' => FatThrowingProvider::class,
        'filament-auto-transliterate.providers.transliterate' => ['throwing'],
    ]);
    Log::spy();

    service()->translate('SECRET-USER-TEXT', 'ur', 'transliterate');

    Log::shouldNotHaveReceived('error', [Mockery::on(fn ($m) => str_contains((string) $m, 'SECRET-USER-TEXT'))]);
    Log::shouldNotHaveReceived('warning', [Mockery::on(fn ($m) => str_contains((string) $m, 'SECRET-USER-TEXT'))]);

    Exceptions::assertReported(fn (SanitizedException $e) => str_starts_with($e->getMessage(), 'RuntimeException at TranslationServiceTest.php:')
        && ! str_contains($e->getMessage(), 'SECRET-USER-TEXT')
        && $e->getPrevious() === null);
    Exceptions::assertNotReported(RuntimeException::class);
});

it('flags a total provider outage as transient', function () {
    config([
        'filament-auto-transliterate.provider_map.throwing' => FatThrowingProvider::class,
        'filament-auto-transliterate.providers.transliterate' => ['throwing'],
    ]);

    $result = service()->translate('verysecret', 'ur', 'transliterate');

    expect($result['success'])->toBeFalse()
        ->and($result['transient'] ?? null)->toBeTrue();
});

it('flags the result transient when every provider breaker is open', function () {
    config([
        'filament-auto-transliterate.provider_failure_threshold' => 1,
        'filament-auto-transliterate.provider_map.throwing' => FatThrowingProvider::class,
        'filament-auto-transliterate.providers.transliterate' => ['throwing'],
    ]);

    service()->translate('wordone', 'ur', 'transliterate'); // trips the breaker
    expect(fatCircuitState('throwing')['open_until'])->toBeGreaterThan(now()->getTimestamp());

    $result = service()->translate('wordtwo', 'ur', 'transliterate'); // not attempted

    expect($result['transient'] ?? null)->toBeTrue();
});

it('does not flag a genuine miss as transient', function () {
    $path = fatTempDictionary(['known' => 'x']);
    config([
        'filament-auto-transliterate.transliterate_dictionary_path' => $path,
        'filament-auto-transliterate.providers.transliterate' => ['transliterate_dictionary'],
    ]);

    $result = service()->translate('unknownword', 'ur', 'transliterate');

    expect($result['success'])->toBeFalse()
        ->and($result)->not->toHaveKey('transient');

    @unlink($path);
});
