<?php

namespace Iabduul7\FilamentAutoTranslate\Macros;

use Filament\Forms\Components\Concerns\HasExtraInputAttributes;
use Filament\Schemas\Components\Component;
use Iabduul7\FilamentAutoTranslate\Enums\TranslationMode;

class TranslatableMacro
{
    public static function register(): void
    {
        Component::macro('translatable', function (bool $enabled = true, TranslationMode|string|null $mode = null) {
            /** @var Component|HasExtraInputAttributes $this */
            if (! $enabled || ! config('filament-auto-translate.enabled', true)) {
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
                    'endpoint' => route('filament-auto-translate.translate'),
                    'targetLang' => config('filament-auto-translate.target_language', 'ur'),
                    'mode' => $mode->value,
                    'minLength' => config('filament-auto-translate.min_text_length', 2),
                    'maxLength' => config('filament-auto-translate.max_text_length', 1000),
                ]),
            ], merge: true);
        });
    }
}
