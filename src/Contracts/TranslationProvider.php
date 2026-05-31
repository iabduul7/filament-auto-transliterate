<?php

namespace Iabduul7\FilamentAutoTransliterate\Contracts;

use Iabduul7\FilamentAutoTransliterate\Data\TranslationResult;

interface TranslationProvider
{
    /**
     * Stable key used in config provider lists and stored as the cache `source`.
     */
    public function key(): string;

    /**
     * Whether this provider has the configuration it needs to run (API keys,
     * endpoints, etc). Unconfigured providers are skipped in the chain.
     */
    public function isConfigured(): bool;

    /**
     * Attempt the conversion. Implementations must never throw: catch their own
     * transport errors and return TranslationResult::failure().
     */
    public function translate(string $text, string $sourceLang, string $targetLang): TranslationResult;
}
