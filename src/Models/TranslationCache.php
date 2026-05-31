<?php

namespace Iabduul7\FilamentAutoTranslate\Models;

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
        return config('filament-auto-translate.table_name', 'translation_cache');
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
     * @return array{total_translations:int, by_source:array<string,int>, avg_confidence:mixed, latest_translation:mixed}
     */
    public static function stats(): array
    {
        return [
            'total_translations' => static::count(),
            'by_source' => static::query()
                ->groupBy('source')
                ->selectRaw('source, count(*) as count')
                ->pluck('count', 'source')
                ->toArray(),
            'avg_confidence' => static::avg('confidence'),
            'latest_translation' => static::latest()->first()?->created_at,
        ];
    }
}
