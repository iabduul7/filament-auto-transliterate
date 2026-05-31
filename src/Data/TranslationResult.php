<?php

namespace Iabduul7\FilamentAutoTransliterate\Data;

/**
 * Immutable result of a single provider attempt or a full translate() call.
 */
final class TranslationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $translated = null,
        public readonly ?string $source = null,
        public readonly float $confidence = 0.0,
        public readonly float $processingTime = 0.0,
        public readonly ?string $original = null,
        public readonly ?string $error = null,
        public readonly ?string $message = null,
    ) {}

    public static function success(
        string $translated,
        string $source,
        float $confidence = 0.8,
        float $processingTime = 0.0,
    ): self {
        return new self(
            success: true,
            translated: $translated,
            source: $source,
            confidence: $confidence,
            processingTime: $processingTime,
        );
    }

    public static function failure(string $error, ?string $original = null): self
    {
        return new self(
            success: false,
            original: $original,
            error: $error,
        );
    }

    /**
     * A non-error "nothing to do" result, e.g. text is empty or already in the
     * target script. The original text is returned untouched.
     */
    public static function noop(string $original, string $message): self
    {
        return new self(
            success: false,
            original: $original,
            message: $message,
        );
    }

    /**
     * Shape returned to the HTTP client and the JS overlay.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'translated' => $this->translated,
            'source' => $this->source,
            'confidence' => $this->confidence,
            'processing_time' => $this->processingTime,
            'original' => $this->original,
            'message' => $this->message,
        ];
    }
}
