<?php

use Iabduul7\FilamentAutoTransliterate\Models\TranslationCache;

/*
| original_text_hash is a generated column on MySQL but a plain column on other
| drivers (SQLite here). The model must populate it on write for non-MySQL
| drivers, otherwise the hash-based cache lookups never match.
*/

it('populates original_text_hash on write so lookups by hash match', function () {
    TranslationCache::create([
        'original_text' => 'hello world',
        'translated_text' => 'ہیلو ورلڈ',
        'target_language' => 'ur',
        'mode' => 'transliterate',
        'source' => 'google_input_tools',
        'confidence' => 0.95,
        'processing_time' => 1.0,
    ]);

    $row = TranslationCache::where('original_text_hash', hash('sha256', 'hello world'))->first();

    expect($row)->not->toBeNull()
        ->and($row->translated_text)->toBe('ہیلو ورلڈ');
});

it('keeps the hash in sync when the original text changes', function () {
    $row = TranslationCache::create([
        'original_text' => 'first',
        'translated_text' => 'x',
        'target_language' => 'ur',
        'mode' => 'transliterate',
        'source' => 'dictionary',
        'confidence' => 0.95,
        'processing_time' => 0.0,
    ]);

    $row->update(['original_text' => 'second']);

    expect($row->fresh()->original_text_hash)->toBe(hash('sha256', 'second'));
});

it('caches and retrieves a translation via the helper methods', function () {
    TranslationCache::cacheTranslation('mohsin', 'موحسن', 'ur', 'google_input_tools', 0.95, 1.2, 'transliterate');

    $found = TranslationCache::getTranslation('mohsin', 'ur', 'transliterate');

    expect($found)->not->toBeNull()
        ->and($found->translated_text)->toBe('موحسن')
        ->and($found->source)->toBe('google_input_tools');
});

it('resolves a null mode to the configured default for legacy callers', function () {
    config(['filament-auto-transliterate.mode' => 'transliterate']);

    $row = TranslationCache::cacheTranslation('city', 'شہر', 'ur', 'dictionary');

    // No mode passed -> resolves to the configured default mode (also the DB
    // column default), so the row is stored under that mode...
    expect($row->translated_text)->toBe('شہر')
        ->and($row->mode)->toBe('transliterate');

    // ...and a mode-less lookup (which resolves the same way) finds it.
    expect(TranslationCache::getTranslation('city', 'ur'))->not->toBeNull();
});

it('does not match across modes', function () {
    TranslationCache::cacheTranslation('school', 'مدرسہ', 'ur', 'mymemory', 0.9, 0.0, 'translate');

    // A transliterate lookup must not return the translate row.
    expect(TranslationCache::getTranslation('school', 'ur', 'transliterate'))->toBeNull()
        ->and(TranslationCache::getTranslation('school', 'ur', 'translate'))->not->toBeNull();
});

it('reports stats including average processing time', function () {
    TranslationCache::cacheTranslation('a', 'ا', 'ur', 'mymemory', 0.8, 2.0, 'translate');
    TranslationCache::cacheTranslation('b', 'ب', 'ur', 'mymemory', 0.9, 4.0, 'translate');

    $stats = TranslationCache::getStats();

    expect($stats['total_translations'])->toBe(2)
        ->and($stats)->toHaveKey('avg_processing_time')
        ->and($stats['by_source']['mymemory'])->toBe(2);
});

it('cleans up low-confidence entries', function () {
    TranslationCache::cacheTranslation('keep', 'ک', 'ur', 'google', 0.9, 0.0, 'transliterate');
    TranslationCache::cacheTranslation('drop', 'ڈ', 'ur', 'transliteration', 0.2, 0.0, 'transliterate');

    $removed = TranslationCache::cleanupLowQuality(0.5);

    expect($removed)->toBe(1)
        ->and(TranslationCache::count())->toBe(1)
        ->and(TranslationCache::getTranslation('keep', 'ur', 'transliterate'))->not->toBeNull();
});
