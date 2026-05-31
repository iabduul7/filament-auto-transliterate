<?php

namespace Iabduul7\FilamentAutoTransliterate\Enums;

/**
 * The two distinct ways the package can convert input.
 *
 * Transliterate: write the same sounds in the target script (Roman Urdu -> Urdu
 * script). On a miss the original text is left unchanged. This mode NEVER falls
 * through to meaning-based translation.
 *
 * Translate: convert by meaning (English -> Urdu). This is an explicit opt-in,
 * never a silent fallback from transliteration.
 */
enum TranslationMode: string
{
    case Transliterate = 'transliterate';
    case Translate = 'translate';

    public static function default(): self
    {
        $value = config('filament-auto-transliterate.mode', self::Transliterate->value);

        return self::tryFrom((string) $value) ?? self::Transliterate;
    }

    /**
     * The config key holding the ordered provider list for this mode.
     */
    public function providersConfigKey(): string
    {
        return "filament-auto-transliterate.providers.{$this->value}";
    }
}
