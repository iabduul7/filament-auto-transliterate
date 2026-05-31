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
