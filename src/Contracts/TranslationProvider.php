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
     * Attempt the conversion. Implementations should not throw: catch their own
     * transport errors and report them correctly:
     *
     *  - TranslationResult::miss($reason)    "no result for this text" (word not
     *    in a dictionary, no candidates, quota-warning text). Never counts
     *    towards the circuit breaker.
     *  - TranslationResult::failure($error)  the provider itself failed
     *    (transport error, timeout, HTTP 4xx/5xx/429, malformed response).
     *    Counts towards the circuit breaker.
     */
    public function translate(string $text, string $sourceLang, string $targetLang): TranslationResult;
}
