<?php

namespace Iabduul7\FilamentAutoTransliterate\Macros;

use Filament\Forms\Components\Concerns\HasExtraInputAttributes;
use Filament\Schemas\Components\Component;
use Iabduul7\FilamentAutoTransliterate\Enums\TranslationMode;

class TranslatableMacro
{
    public static function register(): void
    {
        $macro = function (bool $enabled = true, TranslationMode|string|null $mode = null) {
            /** @var Component|HasExtraInputAttributes $this */
            if (! $enabled || ! config('filament-auto-transliterate.enabled', true)) {
                return $this;
            }

            $mode = $mode instanceof TranslationMode
                ? $mode
                : (TranslationMode::tryFrom((string) $mode) ?? TranslationMode::default());

            // The JS overlay reads these data attributes. The endpoint is the
            // package's named route, so a host can re-prefix it freely.
            return $this->extraInputAttributes([
                'data-fat-translatable' => 'true',
                'data-fat-config' => json_encode([
                    'endpoint' => route('filament-auto-transliterate.translate'),
                    'targetLang' => config('filament-auto-transliterate.target_language', 'ur'),
                    'mode' => $mode->value,
                    'minLength' => config('filament-auto-transliterate.min_text_length', 2),
                    'maxLength' => config('filament-auto-transliterate.max_text_length', 1000),
                ]),
            ], merge: true);
        };

        // Primary macro. Hosts opt fields in with ->translatable(). Filament has
        // no built-in `translatable` macro, so this is safe in a standard panel;
        // a host that already defines its own can use the ->autoTransliterate()
        // alias below instead.
        Component::macro('translatable', $macro);

        // Descriptive alias, also useful when a host app defines its own
        // `translatable` macro and wants an unambiguous name.
        Component::macro('autoTransliterate', $macro);
    }
}
