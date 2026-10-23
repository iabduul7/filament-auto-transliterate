<?php

namespace Iabduul7\FilamentAutoTransliterate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @property string $original_text
 * @property string $translated_text
 * @property string $target_language
 * @property string $mode
 * @property string $source
 * @property float $confidence
 * @property float $processing_time
 * @property string $original_text_hash
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TranslationCache extends Model
{
    protected $table = 'translation_cache';

    protected $fillable = [
        'original_text',
        'translated_text',
        'target_language',
        'mode',
        'source',
        'confidence',
        'processing_time',
        'user_id',
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
        'processing_time' => 'decimal:2',
    ];

    public function getTable(): string
    {
        return config('filament-auto-transliterate.table_name', 'translation_cache');
    }

    protected static function booted(): void
    {
        // On MySQL `original_text_hash` is a generated column (SHA2), so the DB
        // fills it and writing to it errors. On every other driver (SQLite,
        // Postgres) it is a plain column the app must populate, otherwise the
        // hash-based lookups never match and the cache is effectively write-only.
        static::saving(function (self $model): void {
            if (DB::connection($model->getConnectionName())->getDriverName() === 'mysql') {
                return;
            }

            // Populate the hash when the text changes, and also backfill it when
            // it is still null — so pre-existing rows (e.g. a host's legacy table
            // adopted by the package) get a hash on their next write and become
            // findable by the hash-indexed lookups.
            if ($model->isDirty('original_text') || $model->original_text_hash === null) {
                $model->original_text_hash = hash('sha256', (string) $model->original_text);
            }
        });
    }

    /**
     * Resolve a possibly-null mode to a concrete one. A null mode (e.g. from a
     * legacy caller) falls back to the configured default mode, which also
     * matches the `mode` column's database default — so lookups and writes always
     * target one specific mode and never match across modes.
     */
    protected static function resolveMode(?string $mode): string
    {
        return $mode ?? (string) config('filament-auto-transliterate.mode', 'transliterate');
    }

    /**
     * Look up a cached conversion by original text + target language + mode.
     * Hash-first for index use, with an exact-text check to guard against
     * SHA-256 collisions. A null mode resolves to the configured default.
     */
    public static function getTranslation(string $originalText, string $targetLang, ?string $mode = null): ?self
    {
        return static::query()
            ->where('original_text_hash', hash('sha256', $originalText))
            ->where('target_language', $targetLang)
            ->where('mode', static::resolveMode($mode))
            ->where('original_text', $originalText)
            ->first();
    }

    /**
     * Permanently cache a conversion result. A null mode resolves to the
     * configured default mode, so a write always targets one specific mode and
     * never overwrites a row of a different mode for the same text/target.
     *
     * The write is an atomic conditional upsert keyed on the UNIQUE
     * (original_text_hash, target_language, mode) index:
     *
     *  - a non-correction source issues one `UPDATE ... WHERE source !=
     *    'user_correction'`, so it can never clobber a native speaker's
     *    correction — even when racing a concurrent correction write;
     *  - a correction replaces whatever is there (older correction included);
     *  - no row matched -> INSERT; if a concurrent writer won the insert (unique
     *    violation) the loser re-reads (and, for a correction, retries its update).
     *
     * Everything goes through the hash, never a scan of the `original_text`
     * TEXT column. `$userId` is stored only on correction rows.
     */
    public static function cacheTranslation(
        string $originalText,
        string $translatedText,
        string $targetLang,
        string $source,
        float $confidence = 0.8,
        float $processingTime = 0.0,
        ?string $mode = null,
        ?int $userId = null,
    ): self {
        $mode = static::resolveMode($mode);
        $isCorrection = $source === 'user_correction';

        $values = [
            'translated_text' => $translatedText,
            'source' => $source,
            'confidence' => $confidence,
            'processing_time' => $processingTime,
        ];

        if ($isCorrection && self::hasUserIdColumn()) {
            $values['user_id'] = $userId;
        }

        if (self::updateExisting($originalText, $targetLang, $mode, $values, $isCorrection) > 0) {
            return static::getTranslation($originalText, $targetLang, $mode)
                ?? self::newInstanceFor($originalText, $targetLang, $mode, $values);
        }

        try {
            $insert = fn () => static::create([
                'original_text' => $originalText,
                'target_language' => $targetLang,
                'mode' => $mode,
            ] + $values);

            // Inside a caller's open transaction a unique violation would abort
            // it on Postgres ("current transaction is aborted"). A nested
            // transaction() is a savepoint, so only the failed INSERT is rolled
            // back; the exception still propagates to the catch below. Outside a
            // transaction (autocommit) nothing can be aborted, so insert directly.
            $connection = static::resolveConnection((new self)->getConnectionName());

            return $connection->transactionLevel() > 0
                ? $connection->transaction($insert)
                : $insert();
        } catch (UniqueConstraintViolationException) {
            // A concurrent writer inserted the row between our UPDATE and INSERT
            // (or the row exists with identical values, which some drivers
            // report as 0 affected rows). A correction must still win over a
            // provider result, so retry its update before re-reading.
            if ($isCorrection) {
                self::updateExisting($originalText, $targetLang, $mode, $values, true);
            }

            return static::getTranslation($originalText, $targetLang, $mode)
                ?? self::newInstanceFor($originalText, $targetLang, $mode, $values);
        }
    }

    /**
     * Record which user submitted a correction on its `user_correction` row
     * (hash lookup; never touches provider rows). No-op if the user_id column
     * has not been migrated yet. Returns the number of rows stamped.
     */
    public static function stampCorrectionUser(string $originalText, string $targetLang, ?string $mode, int $userId): int
    {
        if (! self::hasUserIdColumn()) {
            return 0;
        }

        return static::query()
            ->where('original_text_hash', hash('sha256', $originalText))
            ->where('target_language', $targetLang)
            ->where('mode', static::resolveMode($mode))
            ->where('original_text', $originalText)
            ->where('source', 'user_correction')
            ->update(['user_id' => $userId]);
    }

    /**
     * Delete cached provider results created more than $days ago. Rows with
     * source `user_correction` are never pruned. Uses only the created_at
     * index (no original_text access). Returns the number of rows removed.
     */
    public static function prune(int $days): int
    {
        return static::query()
            ->where('source', '!=', 'user_correction')
            ->where('created_at', '<', now()->subDays(max(0, $days)))
            ->delete();
    }

    /**
     * One conditional UPDATE against the existing row, if any. Non-corrections
     * never match a row that is already a user correction.
     *
     * @param  array<string, mixed>  $values
     */
    private static function updateExisting(string $originalText, string $targetLang, string $mode, array $values, bool $isCorrection): int
    {
        $query = static::query()
            ->where('original_text_hash', hash('sha256', $originalText))
            ->where('target_language', $targetLang)
            ->where('mode', $mode)
            ->where('original_text', $originalText);

        if (! $isCorrection) {
            $query->where('source', '!=', 'user_correction');
        }

        return $query->update($values);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function newInstanceFor(string $originalText, string $targetLang, string $mode, array $values): self
    {
        return new self([
            'original_text' => $originalText,
            'target_language' => $targetLang,
            'mode' => $mode,
        ] + $values);
    }

    /**
     * Hosts that upgraded without running the user_id migration keep working:
     * the column is simply not written. Memoised per table for the process.
     */
    private static function hasUserIdColumn(): bool
    {
        static $known = [];

        $table = (new self)->getTable();

        return $known[$table] ??= Schema::hasColumn($table, 'user_id');
    }

    /**
     * @return array{total_translations:int, by_source:array<string,int>, avg_confidence:mixed, avg_processing_time:mixed, latest_translation:mixed}
     */
    public static function getStats(): array
    {
        return [
            'total_translations' => static::count(),
            'by_source' => static::query()
                ->groupBy('source')
                ->selectRaw('source, count(*) as count')
                ->pluck('count', 'source')
                ->toArray(),
            'avg_confidence' => static::avg('confidence'),
            'avg_processing_time' => static::avg('processing_time'),
            'latest_translation' => static::latest()->first()?->created_at,
        ];
    }

    /**
     * Back-compat alias. Prefer getStats().
     *
     * @return array<string, mixed>
     */
    public static function stats(): array
    {
        return static::getStats();
    }

    /**
     * Delete low-confidence cached entries. Returns the number removed.
     */
    public static function cleanupLowQuality(float $minConfidence = 0.5): int
    {
        return static::where('confidence', '<', $minConfidence)->delete();
    }
}
