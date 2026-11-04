<?php

namespace Iabduul7\FilamentAutoTransliterate\Commands;

use Iabduul7\FilamentAutoTransliterate\Models\TranslationCache;
use Illuminate\Console\Command;

class PruneTranslationCacheCommand extends Command
{
    protected $signature = 'filament-auto-transliterate:prune {--days= : Delete cached provider results older than this many days (defaults to the cache_ttl_days config)}';

    protected $description = 'Delete cached provider results older than N days (user corrections are never pruned)';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('filament-auto-transliterate.cache_ttl_days');

        if ($days === null || $days === '' || ! is_numeric($days) || (int) $days < 1) {
            $this->components->info('Nothing to prune: pass --days=N or set the cache_ttl_days config.');

            return self::SUCCESS;
        }

        $deleted = TranslationCache::prune((int) $days);

        $this->components->info("Pruned {$deleted} cached translation(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
