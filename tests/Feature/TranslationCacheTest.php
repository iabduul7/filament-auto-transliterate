<?php

use Iabduul7\FilamentAutoTransliterate\Models\TranslationCache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

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

it('updates an existing row in place rather than duplicating', function () {
    $first = TranslationCache::cacheTranslation('rent', 'کرایہ', 'ur', 'mymemory', 0.8, 1.0, 'transliterate');
    $second = TranslationCache::cacheTranslation('rent', 'کرایہ-v2', 'ur', 'google_input_tools', 0.95, 2.0, 'transliterate');

    // Same text+lang+mode -> one row, updated (not a second insert).
    expect(TranslationCache::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and(TranslationCache::getTranslation('rent', 'ur', 'transliterate')->translated_text)->toBe('کرایہ-v2');
});

it('never lets a non-correction source overwrite a stored user correction', function () {
    TranslationCache::cacheTranslation('mkan', 'مکان', 'ur', 'user_correction', 0.99, 0.0, 'transliterate');

    // A provider (or dictionary) result for the same text/target/mode must not
    // clobber the ground-truth correction (docs/02-self-improvement.md).
    TranslationCache::cacheTranslation('mkan', 'مکن', 'ur', 'google_input_tools', 0.95, 1.0, 'transliterate');

    $row = TranslationCache::getTranslation('mkan', 'ur', 'transliterate');

    expect($row->translated_text)->toBe('مکان')
        ->and($row->source)->toBe('user_correction');
});

it('lets a newer user correction replace an older one', function () {
    TranslationCache::cacheTranslation('mkan', 'مکن', 'ur', 'user_correction', 0.99, 0.0, 'transliterate');
    TranslationCache::cacheTranslation('mkan', 'مکان', 'ur', 'user_correction', 0.99, 0.0, 'transliterate');

    $row = TranslationCache::getTranslation('mkan', 'ur', 'transliterate');

    expect($row->translated_text)->toBe('مکان')
        ->and(TranslationCache::count())->toBe(1);
});

it('backfills a null original_text_hash on the next save', function () {
    // Simulate a legacy/adopted row that predates the hash population (e.g. a
    // host's existing table). Insert without going through the model hook.
    $id = DB::table((new TranslationCache)->getTable())->insertGetId([
        'original_text' => 'legacy',
        'translated_text' => 'پرانا',
        'target_language' => 'ur',
        'mode' => 'transliterate',
        'source' => 'dictionary',
        'confidence' => 0.9,
        'processing_time' => 0.0,
        'original_text_hash' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Not findable by hash yet.
    expect(TranslationCache::getTranslation('legacy', 'ur', 'transliterate'))->toBeNull();

    // A no-text-change save should still backfill the hash.
    $row = TranslationCache::find($id);
    $row->update(['source' => 'mymemory']);

    expect($row->fresh()->original_text_hash)->toBe(hash('sha256', 'legacy'))
        ->and(TranslationCache::getTranslation('legacy', 'ur', 'transliterate'))->not->toBeNull();
});

/*
| Atomic upsert, unique index, user_id and pruning.
*/

function fatInsertRaw(string $text, string $source, string $translated = 'x', ?string $createdAt = null): void
{
    DB::table((new TranslationCache)->getTable())->insert([
        'original_text' => $text,
        'translated_text' => $translated,
        'target_language' => 'ur',
        'mode' => 'transliterate',
        'source' => $source,
        'confidence' => 0.9,
        'processing_time' => 0.0,
        'original_text_hash' => hash('sha256', $text),
        'created_at' => $createdAt ?? now(),
        'updated_at' => $createdAt ?? now(),
    ]);
}

it('enforces a unique lookup index on (hash, target, mode)', function () {
    fatInsertRaw('dup', 'dictionary');

    expect(fn () => fatInsertRaw('dup', 'mymemory'))->toThrow(UniqueConstraintViolationException::class);

    // A different mode or target is a different key.
    TranslationCache::cacheTranslation('dup', 'y', 'ur', 'mymemory', 0.8, 0.0, 'translate');
    TranslationCache::cacheTranslation('dup', 'z', 'hi', 'mymemory', 0.8, 0.0, 'transliterate');

    expect(TranslationCache::count())->toBe(3);
});

it('refuses a duplicate row created through the model', function () {
    $attributes = [
        'original_text' => 'dup',
        'translated_text' => 'x',
        'target_language' => 'ur',
        'mode' => 'transliterate',
        'source' => 'dictionary',
    ];

    TranslationCache::create($attributes);

    expect(fn () => TranslationCache::create($attributes))->toThrow(UniqueConstraintViolationException::class);
});

it('returns the stored row from cacheTranslation()', function () {
    $row = TranslationCache::cacheTranslation('mkan', 'مکان', 'ur', 'user_correction', 0.99, 0.0, 'transliterate', 5);

    expect($row->exists)->toBeTrue()
        ->and($row->id)->not->toBeNull()
        ->and($row->user_id)->toBe(5);
});

it('stores user_id only on user_correction rows', function () {
    TranslationCache::cacheTranslation('a1', 'ا', 'ur', 'google_input_tools', 0.9, 0.0, 'transliterate', 5);
    TranslationCache::cacheTranslation('b1', 'ب', 'ur', 'user_correction', 0.99, 0.0, 'transliterate', 5);

    expect(TranslationCache::getTranslation('a1', 'ur', 'transliterate')->user_id)->toBeNull()
        ->and(TranslationCache::getTranslation('b1', 'ur', 'transliterate')->user_id)->toBe(5);
});

it('keeps the correcting user when a provider result is dropped for a correction row', function () {
    TranslationCache::cacheTranslation('mkan', 'مکان', 'ur', 'user_correction', 0.99, 0.0, 'transliterate', 5);
    TranslationCache::cacheTranslation('mkan', 'مکن', 'ur', 'google_input_tools', 0.95, 1.0, 'transliterate');

    $row = TranslationCache::getTranslation('mkan', 'ur', 'transliterate');

    expect($row->source)->toBe('user_correction')
        ->and($row->translated_text)->toBe('مکان')
        ->and($row->user_id)->toBe(5);
});

it('a newer correction replaces an older one and its user', function () {
    TranslationCache::cacheTranslation('mkan', 'مکن', 'ur', 'user_correction', 0.99, 0.0, 'transliterate', 5);
    TranslationCache::cacheTranslation('mkan', 'مکان', 'ur', 'user_correction', 0.99, 0.0, 'transliterate', 6);

    $row = TranslationCache::getTranslation('mkan', 'ur', 'transliterate');

    expect(TranslationCache::count())->toBe(1)
        ->and($row->translated_text)->toBe('مکان')
        ->and($row->user_id)->toBe(6);
});

it('a correction replaces an existing provider row', function () {
    TranslationCache::cacheTranslation('mkan', 'مکن', 'ur', 'google_input_tools', 0.95, 1.0, 'transliterate');
    TranslationCache::cacheTranslation('mkan', 'مکان', 'ur', 'user_correction', 0.99, 0.0, 'transliterate', 5);

    $row = TranslationCache::getTranslation('mkan', 'ur', 'transliterate');

    expect(TranslationCache::count())->toBe(1)
        ->and($row->source)->toBe('user_correction')
        ->and($row->translated_text)->toBe('مکان');
});

it('survives losing the insert race to a concurrent provider write (re-reads the winner)', function () {
    $raced = false;
    Event::listen('eloquent.creating: '.TranslationCache::class, function () use (&$raced) {
        if (! $raced) {
            $raced = true;
            fatInsertRaw('racy', 'mymemory', 'winner');
        }
    });

    $row = TranslationCache::cacheTranslation('racy', 'loser', 'ur', 'google_input_tools', 0.9, 0.0, 'transliterate');

    expect($raced)->toBeTrue()
        ->and(TranslationCache::count())->toBe(1)
        ->and($row->translated_text)->toBe('winner');
});

it('a correction still wins when it loses the insert race to a provider write', function () {
    $raced = false;
    Event::listen('eloquent.creating: '.TranslationCache::class, function () use (&$raced) {
        if (! $raced) {
            $raced = true;
            fatInsertRaw('racy', 'mymemory', 'winner');
        }
    });

    $row = TranslationCache::cacheTranslation('racy', 'مکان', 'ur', 'user_correction', 0.99, 0.0, 'transliterate', 3);

    expect(TranslationCache::count())->toBe(1)
        ->and($row->source)->toBe('user_correction')
        ->and($row->translated_text)->toBe('مکان')
        ->and($row->user_id)->toBe(3);
});

it('a provider write never overwrites a correction that won the insert race', function () {
    $raced = false;
    Event::listen('eloquent.creating: '.TranslationCache::class, function () use (&$raced) {
        if (! $raced) {
            $raced = true;
            fatInsertRaw('racy', 'user_correction', 'ground-truth');
        }
    });

    $row = TranslationCache::cacheTranslation('racy', 'provider', 'ur', 'google_input_tools', 0.9, 0.0, 'transliterate');

    expect($row->source)->toBe('user_correction')
        ->and($row->translated_text)->toBe('ground-truth');
});

it('prunes only old non-correction rows', function () {
    fatInsertRaw('old-provider', 'google_input_tools', 'x', now()->subDays(40)->toDateTimeString());
    fatInsertRaw('old-correction', 'user_correction', 'x', now()->subDays(400)->toDateTimeString());
    fatInsertRaw('new-provider', 'google_input_tools');

    $deleted = TranslationCache::prune(30);

    expect($deleted)->toBe(1)
        ->and(TranslationCache::getTranslation('old-provider', 'ur', 'transliterate'))->toBeNull()
        ->and(TranslationCache::getTranslation('old-correction', 'ur', 'transliterate'))->not->toBeNull()
        ->and(TranslationCache::getTranslation('new-provider', 'ur', 'transliterate'))->not->toBeNull();
});

it('prunes through the artisan command with --days', function () {
    fatInsertRaw('old-provider', 'mymemory', 'x', now()->subDays(40)->toDateTimeString());
    fatInsertRaw('old-correction', 'user_correction', 'x', now()->subDays(40)->toDateTimeString());
    fatInsertRaw('new-provider', 'mymemory');

    $this->artisan('filament-auto-transliterate:prune', ['--days' => 30])->assertSuccessful();

    expect(TranslationCache::pluck('original_text')->sort()->values()->all())->toBe(['new-provider', 'old-correction']);
});

it('uses cache_ttl_days when --days is omitted, and does nothing when neither is set', function () {
    fatInsertRaw('old-provider', 'mymemory', 'x', now()->subDays(40)->toDateTimeString());

    $this->artisan('filament-auto-transliterate:prune')->assertSuccessful();
    expect(TranslationCache::count())->toBe(1);

    config(['filament-auto-transliterate.cache_ttl_days' => 30]);
    $this->artisan('filament-auto-transliterate:prune')->assertSuccessful();
    expect(TranslationCache::count())->toBe(0);
});

it('upgrade migration is a no-op on a fresh schema', function () {
    $migration = include __DIR__.'/../../database/migrations/add_user_id_and_unique_index_to_translation_cache_table.php.stub';
    $migration->up();

    expect(Schema::hasColumn('translation_cache', 'user_id'))->toBeTrue();
});

it('upgrade migration adds user_id, dedupes (keeping corrections) and makes the index unique', function () {
    // SQLite index names are schema-global, so replace the fresh table with the legacy one.
    Schema::drop('translation_cache');
    config(['filament-auto-transliterate.table_name' => 'legacy_cache']);

    Schema::create('legacy_cache', function (Blueprint $table) {
        $table->id();
        $table->text('original_text');
        $table->text('translated_text');
        $table->string('target_language', 10)->default('ur');
        $table->string('mode', 20)->default('transliterate');
        $table->string('source', 30);
        $table->decimal('confidence', 3, 2)->default(0.80);
        $table->decimal('processing_time', 8, 2)->default(0.00);
        $table->timestamps();
        $table->string('original_text_hash', 64)->nullable();
        $table->index(['original_text_hash', 'target_language', 'mode'], 'fat_cache_lookup_index');
    });

    $row = fn (string $source, string $text) => [
        'original_text' => 'dup', 'translated_text' => $text, 'target_language' => 'ur', 'mode' => 'transliterate',
        'source' => $source, 'original_text_hash' => hash('sha256', 'dup'),
    ];
    DB::table('legacy_cache')->insert([$row('user_correction', 'keep'), $row('mymemory', 'newer-provider')]);
    // A legacy row from before hashes were populated: NULL hash.
    DB::table('legacy_cache')->insert([
        'original_text' => 'nullhash', 'translated_text' => 'old', 'target_language' => 'ur', 'mode' => 'transliterate',
        'source' => 'mymemory', 'original_text_hash' => null,
    ]);

    $migration = include __DIR__.'/../../database/migrations/add_user_id_and_unique_index_to_translation_cache_table.php.stub';
    $migration->up();

    expect(Schema::hasColumn('legacy_cache', 'user_id'))->toBeTrue()
        ->and(DB::table('legacy_cache')->orderBy('id')->pluck('translated_text')->all())->toBe(['keep', 'old'])
        ->and(DB::table('legacy_cache')->where('original_text', 'nullhash')->value('original_text_hash'))->toBe(hash('sha256', 'nullhash'))
        ->and(TranslationCache::getTranslation('nullhash', 'ur', 'transliterate')?->translated_text)->toBe('old')
        ->and(collect(Schema::getIndexes('legacy_cache'))->firstWhere('name', 'fat_cache_lookup_index')['unique'])->toBeTrue();

    // Protected by the unique index: a second write updates in place instead of duplicating.
    TranslationCache::cacheTranslation('nullhash', 'fresh', 'ur', 'mymemory', 0.9, 0.0, 'transliterate');
    expect(DB::table('legacy_cache')->where('original_text', 'nullhash')->count())->toBe(1)
        ->and(TranslationCache::getTranslation('nullhash', 'ur', 'transliterate')->translated_text)->toBe('fresh');

    // Re-running is safe.
    $migration->up();
});

it('writes through a savepoint when called inside a caller transaction', function () {
    DB::transaction(function () {
        $row = TranslationCache::cacheTranslation('intx', 'a', 'ur', 'mymemory', 0.9, 0.0, 'transliterate');
        $again = TranslationCache::cacheTranslation('intx', 'b', 'ur', 'mymemory', 0.9, 0.0, 'transliterate');

        expect(DB::transactionLevel())->toBe(1)
            ->and($row->translated_text)->toBe('a')
            ->and($again->translated_text)->toBe('b');
    });

    expect(TranslationCache::count())->toBe(1)
        ->and(TranslationCache::getTranslation('intx', 'ur', 'transliterate')->translated_text)->toBe('b');
});
