<?php

namespace Iabduul7\FilamentAutoTransliterate\Data;

/**
 * Immutable result of a single provider attempt or a full translate() call.
 *
 * An unsuccessful provider attempt is one of two kinds:
 *   - a MISS  ({@see self::miss()}): the provider worked but has no answer for
 *     this text (word not in dictionary, no candidates, quota-warning text).
 *     Normal and expected; it must never count towards the circuit breaker.
 *   - an ERROR ({@see self::failure()}): the provider itself misbehaved
 *     (transport exception, timeout, HTTP 4xx/5xx/429, malformed payload).
 *     Only errors trip the breaker.
 */
final class TranslationResult
{
    public const KIND_ERROR = 'error';

    public const KIND_MISS = 'miss';

    public function __construct(
        public readonly bool $success,
        public readonly ?string $translated = null,
        public readonly ?string $source = null,
        public readonly float $confidence = 0.0,
        public readonly float $processingTime = 0.0,
        public readonly ?string $original = null,
        public readonly ?string $error = null,
        public readonly ?string $message = null,
        /** @var list<string> extra candidates beyond the applied translation (doc 04) */
        public readonly array $alternatives = [],
        /** @var self::KIND_*|null null for success and for noop results */
        public readonly ?string $failureKind = null,
        /** True when "no result" is due to a provider outage, not a genuine miss (clients must not negative-cache it). */
        public readonly bool $transient = false,
    ) {}

    /**
     * @param  list<string>  $alternatives
     */
    public static function success(
        string $translated,
        string $source,
        float $confidence = 0.8,
        float $processingTime = 0.0,
        array $alternatives = [],
    ): self {
        return new self(
            success: true,
            translated: $translated,
            source: $source,
            confidence: $confidence,
            processingTime: $processingTime,
            alternatives: $alternatives,
        );
    }

    /**
     * A real provider ERROR (transport failure, bad HTTP status, malformed
     * response). Counts towards the circuit breaker.
     */
    public static function failure(string $error, ?string $original = null): self
    {
        return new self(
            success: false,
            original: $original,
            error: $error,
            failureKind: self::KIND_ERROR,
        );
    }

    /**
     * A provider MISS: "no result for this text". Not an error — the service
     * moves on to the next provider without touching the circuit breaker.
     */
    public static function miss(string $reason, ?string $original = null): self
    {
        return new self(
            success: false,
            original: $original,
            error: $reason,
            failureKind: self::KIND_MISS,
        );
    }

    /**
     * A non-error "nothing to do" result, e.g. text is empty or already in the
     * target script. The original text is returned untouched.
     */
    public static function noop(string $original, string $message, bool $transient = false): self
    {
        return new self(
            success: false,
            original: $original,
            message: $message,
            transient: $transient,
        );
    }

    /** True only for a real provider error (the sole kind the breaker counts). */
    public function isError(): bool
    {
        return ! $this->success && $this->failureKind === self::KIND_ERROR;
    }

    /** True for a "no result for this text" outcome, including legacy noops. */
    public function isMiss(): bool
    {
        return ! $this->success && $this->failureKind !== self::KIND_ERROR;
    }

    /**
     * Shape returned to the HTTP client and the JS overlay. Unchanged by the
     * miss/error distinction (internal-only metadata is not exposed), except
     * `transient => true`, added only when the result is an outage, not a miss.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'success' => $this->success,
            'translated' => $this->translated,
            'source' => $this->source,
            'confidence' => $this->confidence,
            'processing_time' => $this->processingTime,
            'original' => $this->original,
            'message' => $this->message,
            'alternatives' => $this->alternatives,
        ];

        if ($this->transient) {
            $data['transient'] = true;
        }

        return $data;
    }
}
