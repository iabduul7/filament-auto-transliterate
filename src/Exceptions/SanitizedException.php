<?php

namespace Iabduul7\FilamentAutoTransliterate\Exceptions;

use RuntimeException;

/**
 * A stand-in for an exception whose message may embed user text (e.g. an HTTP
 * client exception carrying the request URL, or a DB exception carrying SQL
 * bindings). Reported to the host's handler/Sentry instead of the original when
 * `log_requests` is off. Deliberately does NOT chain the original as `previous`:
 * reporters walk the previous chain and would leak the original message.
 */
class SanitizedException extends RuntimeException
{
    public static function from(\Throwable $e): self
    {
        return new self(get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    }
}
