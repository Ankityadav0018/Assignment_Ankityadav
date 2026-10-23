<?php

namespace App\Enums;

/**
 * Lifecycle states for a PolicyRequest.
 *
 * ┌──────────┐
 * │ RECEIVED │  Record inserted; raw input accepted.
 * └─────┬────┘
 *       │
 * ┌─────▼─────┐
 * │ VALIDATED │  Validated; premium snapshot stored.
 * └─────┬─────┘
 *       │
 * ┌─────▼─────┐  retryable failure
 * │ SUBMITTED ├─────────────────────────┐
 * └─────┬─────┘                         │
 *       │                               │
 *       ├──────────────┬────────────────┴────────────┐
 *       │              │                             │
 * ┌─────▼─────┐ ┌──────▼──────────────┐ ┌────────────▼─────────┐
 * │   ISSUED  │ │ PENDING_CONFIRMATION│ │ NEEDS_RECONCILIATION │
 * │ (terminal)│ └──────┬──────────────┘ └────────────┬─────────┘
 * └───────────┘        │                             │
 *       ▲              ├─────────────────────────────┤
 *       │              │                             │
 *       │       ┌──────▼─────┐                       │
 *       └───────┤   FAILED   │◄──────────────────────┘
 *               │ (terminal) │
 *               └────────────┘
 */
enum PolicyStatus: string
{
    case Received            = 'RECEIVED';
    case Validated           = 'VALIDATED';
    case Submitted           = 'SUBMITTED';
    case Issued              = 'ISSUED';
    case PendingConfirmation = 'PENDING_CONFIRMATION';
    case Failed              = 'FAILED';
    case NeedsReconciliation = 'NEEDS_RECONCILIATION';

    /**
     * Determine if this state is terminal (no further transitions permitted).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Issued,
            self::Failed => true,
            default      => false,
        };
    }
}
