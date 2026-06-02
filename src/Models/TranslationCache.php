<?php

namespace Iabduul7\FilamentAutoTransliterate\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @property string $original_text
 * @property string $translated_text
 * @property string $target_language
 * @property string $mode
 * @property string $source
 * @property float $confidence
 * @property float $processing_time
 * @property string $original_text_hash
 */
class TranslationCache extends Model
{
    use HasFactory;

    protected $table = 'translation_cache';

    protected $fillable = [
        'original_text',
        'translated_text',
        'target_language',
        'mode',
        'source',
        'confidence',
        'processing_time',
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
     */
    public static function cacheTranslation(
        string $originalText,
        string $translatedText,
        string $targetLang,
        string $source,
        float $confidence = 0.8,
        float $processingTime = 0.0,
        ?string $mode = null,
    ): self {
        $mode = static::resolveMode($mode);

        // Find via the hash-indexed lookup rather than matching on the unindexed
        // `original_text` TEXT column (which would force a full table scan on
        // every write). Update the existing row or create a new one.
        $model = static::getTranslation($originalText, $targetLang, $mode) ?? new static([
            'original_text' => $originalText,
            'target_language' => $targetLang,
            'mode' => $mode,
        ]);

        $model->fill([
            'translated_text' => $translatedText,
            'source' => $source,
            'confidence' => $confidence,
            'processing_time' => $processingTime,
        ])->save();

        return $model;
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
