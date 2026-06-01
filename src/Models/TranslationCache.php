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

            if ($model->isDirty('original_text')) {
                $model->original_text_hash = hash('sha256', (string) $model->original_text);
            }
        });
    }

    /**
     * Look up a cached conversion by original text + target language (+ optional
     * mode). Hash-first for index use, with an exact-text check to guard against
     * SHA-256 collisions.
     */
    public static function getTranslation(string $originalText, string $targetLang, ?string $mode = null): ?self
    {
        return static::query()
            ->where('original_text_hash', hash('sha256', $originalText))
            ->where('target_language', $targetLang)
            ->when($mode !== null, fn ($q) => $q->where('mode', $mode))
            ->where('original_text', $originalText)
            ->first();
    }

    /**
     * Permanently cache a conversion result. `mode` is optional so legacy callers
     * that predate the transliterate/translate split keep working.
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
        $attributes = [
            'original_text' => $originalText,
            'target_language' => $targetLang,
        ];

        if ($mode !== null) {
            $attributes['mode'] = $mode;
        }

        return static::updateOrCreate($attributes, [
            'translated_text' => $translatedText,
            'source' => $source,
            'confidence' => $confidence,
            'processing_time' => $processingTime,
        ]);
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
