<?php

namespace App\Enums;

/**
 * Lifecycle states for a PolicyRequest.
 *
 * ┌──────────┐
 * │ RECEIVED │  Record inserted; raw input accepted.
 * └─────┬────┘
 *       │ validation + premium calculation OK
 * ┌─────▼─────┐
 * │ VALIDATED │  All fields valid, premium snapshot stored.
 * └─────┬─────┘
 *       │ submit to provider
 * ┌─────▼──────┐
 * │ PROCESSING │  Request sent to provider, awaiting response.
 * └─────┬──────┘
 *       ├─ success ──► ISSUED
 *       ├─ soft error ► RETRYING
 *       └─ hard error ► FAILED
 *
 * RETRYING re-enters PROCESSING on each retry attempt.
 */
enum PolicyStatus: string
{
    case Received   = 'RECEIVED';
    case Validated  = 'VALIDATED';
    case Processing = 'PROCESSING';
    case Retrying   = 'RETRYING';
    case Issued     = 'ISSUED';
    case Failed     = 'FAILED';
    case Cancelled  = 'CANCELLED';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Issued,
            self::Failed,
            self::Cancelled => true,
            default         => false,
        };
    }
}
