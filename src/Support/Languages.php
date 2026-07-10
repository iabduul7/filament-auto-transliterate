<?php

namespace Iabduul7\FilamentAutoTransliterate\Support;

/**
 * Registry for the `languages` config map (docs/01-multi-language.md). Every
 * target-language lookup in the package — script detection, validation,
 * default resolution, the JS payload — goes through this class rather than
 * reading the config array directly, so there is one place that understands
 * the shape of a language entry.
 */
class Languages
{
    /**
     * @return array<string, array{label:string, native:string, rtl:bool, script_ranges:list<array{0:string,1:string}>, itc?:string}>
     */
    public static function all(): array
    {
        return (array) config('filament-auto-transliterate.languages', []);
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(static::all());
    }

    /**
     * A valid language code, or the configured default target language.
     * Callers use this so an unknown/garbage `target_lang` can never reach a
     * provider — defense-in-depth beyond the controller's own validation.
     */
    public static function resolve(?string $code): string
    {
        $default = (string) config('filament-auto-transliterate.target_language', 'ur');

        if ($code !== null && array_key_exists($code, static::all())) {
            return $code;
        }

        return $default;
    }

    /**
     * Compiled PHP preg pattern matching any character in the language's
     * script ranges (e.g. `/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u` for Urdu),
     * or null when the language is unknown or has no ranges configured.
     */
    public static function phpScriptPattern(string $code): ?string
    {
        $ranges = static::all()[$code]['script_ranges'] ?? [];

        if ($ranges === []) {
            return null;
        }

        $body = implode('', array_map(
            fn (array $range) => '\x{'.$range[0].'}-\x{'.$range[1].'}',
            $ranges,
        ));

        return "/[{$body}]/u";
    }

    /**
     * Compiled JS regex source — actual characters (via mb_chr), not \u
     * escapes, e.g. `[؀-ۿ]` for Urdu — safe to json_encode for the frontend.
     * Null when the language is unknown or has no ranges configured.
     */
    public static function jsPattern(string $code): ?string
    {
        $ranges = static::all()[$code]['script_ranges'] ?? [];

        if ($ranges === []) {
            return null;
        }

        $body = implode('', array_map(
            fn (array $range) => mb_chr((int) hexdec($range[0])).'-'.mb_chr((int) hexdec($range[1])),
            $ranges,
        ));

        return "[{$body}]";
    }

    public static function isRtl(string $code): bool
    {
        return (bool) (static::all()[$code]['rtl'] ?? false);
    }

    /**
     * Frontend payload consumed by the header language switcher (doc 03):
     * `{code: {label, native, rtl, pattern}}`.
     *
     * @return array<string, array{label:string, native:string, rtl:bool, pattern:?string}>
     */
    public static function forJs(): array
    {
        $payload = [];

        foreach (static::all() as $code => $language) {
            $payload[$code] = [
                'label' => $language['label'] ?? $code,
                'native' => $language['native'] ?? $code,
                'rtl' => (bool) ($language['rtl'] ?? false),
                'pattern' => static::jsPattern($code),
            ];
        }

        return $payload;
    }
}
