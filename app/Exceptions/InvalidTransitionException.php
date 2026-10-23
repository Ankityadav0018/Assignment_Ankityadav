<?php

namespace App\Exceptions;

use App\Enums\PolicyStatus;
use DomainException;

/**
 * Thrown when an illegal state transition is attempted on a PolicyRequest.
 */
class InvalidTransitionException extends DomainException
{
    public function __construct(
        public readonly PolicyStatus $from,
        public readonly PolicyStatus $to,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $msg = $message !== '' ? $message : sprintf(
            'Cannot transition policy request from %s to %s.',
            $from->value,
            $to->value,
        );

        parent::__construct($msg, $code, $previous);
    }
}
