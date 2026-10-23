<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a transient / retriable insurer error occurs.
 */
class InsurerTemporaryFailureException extends RuntimeException
{
    public function __construct(
        string $message = 'Provider temporarily unavailable. Please retry.',
        public readonly ?string $idempotencyKey = null,
        int $code = 503,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
