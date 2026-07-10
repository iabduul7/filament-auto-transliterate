<?php

namespace Iabduul7\FilamentAutoTransliterate\Macros;

use Filament\Forms\Components\Concerns\HasExtraInputAttributes;
use Filament\Schemas\Components\Component;
use Iabduul7\FilamentAutoTransliterate\Enums\TranslationMode;
use Iabduul7\FilamentAutoTransliterate\Support\Languages;

class TranslatableMacro
{
    public static function register(): void
    {
        $macro = function (bool $enabled = true, TranslationMode|string|null $mode = null, ?string $target = null) {
            /** @var Component|HasExtraInputAttributes $this */
            if (! $enabled || ! config('filament-auto-transliterate.enabled', true)) {
                return $this;
            }

            $mode = $mode instanceof TranslationMode
                ? $mode
                : (TranslationMode::tryFrom((string) $mode) ?? TranslationMode::default());

            // A field can pin its own target language (docs/01-multi-language.md,
            // e.g. ->translatable(target: 'hi')), overriding the header switcher.
            // An unknown code falls back to the configured default rather than
            // reaching a provider with garbage.
            $targetPinned = $target !== null;
            $targetLang = $targetPinned
                ? Languages::resolve($target)
                : config('filament-auto-transliterate.target_language', 'ur');

            $attributes = [
                'data-fat-translatable' => 'true',
                // The JS overlay reads these data attributes. The endpoints are
                // the package's named routes, so a host can re-prefix them freely.
                'data-fat-config' => json_encode([
                    'endpoint' => route('filament-auto-transliterate.translate'),
                    'learnEndpoint' => route('filament-auto-transliterate.learn'),
                    'targetLang' => $targetLang,
                    'targetPinned' => $targetPinned,
                    'mode' => $mode->value,
                    'minLength' => config('filament-auto-transliterate.min_text_length', 2),
                    'maxLength' => config('filament-auto-transliterate.max_text_length', 1000),
                ]),
            ];

            if ($targetPinned) {
                $attributes['data-fat-pinned'] = $targetLang;
            }

            return $this->extraInputAttributes($attributes, merge: true);
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
