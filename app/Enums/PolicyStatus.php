<?php

namespace App\Enums;

/**
 * Lifecycle states for a PolicyRequest.
 *
 * ┌─────────────┐
 * │   PENDING   │  Record created, not yet submitted.
 * └──────┬──────┘
 *        │ submit
 * ┌──────▼──────┐
 * │ PROCESSING  │  Sent to provider, awaiting response.
 * └──────┬──────┘
 *        ├─ success ──► ISSUED
 *        ├─ soft error ► RETRYING
 *        └─ hard error ► FAILED
 *
 * RETRYING re-enters PROCESSING on each retry attempt.
 */
enum PolicyStatus: string
{
    case Pending    = 'PENDING';
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
