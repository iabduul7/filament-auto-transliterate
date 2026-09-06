<?php

namespace Iabduul7\FilamentAutoTransliterate\Providers;

/**
 * Local JSON dictionary for transliterate mode — a { "roman word": "target
 * script word" } map. Kept as its own subclass (rather than reusing
 * DictionaryProvider's `dictionary_path`) so a meaning-glossary can never
 * answer a phonetic query, preserving the transliterate/translate isolation
 * guarantee. See docs/04-free-tier-quality.md.
 */
class TransliterationDictionaryProvider extends DictionaryProvider
{
    public function key(): string
    {
        return 'transliterate_dictionary';
    }

    protected function pathConfigKey(): string
    {
        return 'transliterate_dictionary_path';
    }
}
