<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an insurer call times out (or simulated via TIMEOUT_AFTER_ISSUE).
 */
class InsurerTimeoutException extends RuntimeException
{
    public function __construct(
        string $message = 'Provider connection timed out after issuance.',
        public readonly ?string $idempotencyKey = null,
        int $code = 504,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
