<?php

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\PolicyRequest;

/**
 * Manages lifecycle status transitions for PolicyRequest entities.
 *
 * Rules:
 *  - RECEIVED             -> VALIDATED
 *  - VALIDATED            -> SUBMITTED
 *  - SUBMITTED            -> ISSUED | PENDING_CONFIRMATION | FAILED | NEEDS_RECONCILIATION | VALIDATED (retryable failure)
 *  - PENDING_CONFIRMATION -> ISSUED | FAILED | NEEDS_RECONCILIATION
 *  - NEEDS_RECONCILIATION -> ISSUED | FAILED | VALIDATED (provider confirmed not found) (SUBMITTED is explicitly NOT allowed)
 *  - ISSUED and FAILED are terminal states (no transitions permitted)
 */
class PolicyStateMachine
{
    /**
     * Explicit map of permitted state transitions: from_status => [to_status, ...].
     */
    public const ALLOWED_TRANSITIONS = [
        PolicyStatus::Received->value => [
            PolicyStatus::Validated->value,
        ],
        PolicyStatus::Validated->value => [
            PolicyStatus::Submitted->value,
        ],
        PolicyStatus::Submitted->value => [
            PolicyStatus::Issued->value,
            PolicyStatus::PendingConfirmation->value,
            PolicyStatus::Failed->value,
            PolicyStatus::NeedsReconciliation->value,
            PolicyStatus::Validated->value, // retryable failure
        ],
        PolicyStatus::PendingConfirmation->value => [
            PolicyStatus::Issued->value,
            PolicyStatus::Failed->value,
            PolicyStatus::NeedsReconciliation->value,
        ],
        PolicyStatus::NeedsReconciliation->value => [
            PolicyStatus::Issued->value,
            PolicyStatus::Failed->value,
            PolicyStatus::Validated->value,
        ],
        PolicyStatus::Issued->value => [
            // Terminal — no transitions allowed
        ],
        PolicyStatus::Failed->value => [
            // Terminal — no transitions allowed
        ],
    ];

    /**
     * Check if a transition from one status to another is permitted.
     */
    public function canTransition(PolicyStatus|string $from, PolicyStatus|string $to): bool
    {
        $fromStatus = $from instanceof PolicyStatus ? $from : PolicyStatus::from($from);
        $toStatus   = $to instanceof PolicyStatus ? $to : PolicyStatus::from($to);

        $allowed = self::ALLOWED_TRANSITIONS[$fromStatus->value] ?? [];

        return in_array($toStatus->value, $allowed, true);
    }

    /**
     * Execute a state transition on the given policy request.
     *
     * Validates that the move is allowed, updates the policy row status,
     * and appends a transition record to policy_state_history inside the
     * caller's database transaction.
     *
     * @param  PolicyRequest        $policy Policy request to transition.
     * @param  PolicyStatus|string  $to     Target status.
     * @param  string|null          $reason Optional human-readable reason / error code (no PII).
     * @return PolicyRequest
     *
     * @throws InvalidTransitionException If the requested transition is illegal.
     */
    public function transition(
        PolicyRequest $policy,
        PolicyStatus|string $to,
        ?string $reason = null,
    ): PolicyRequest {
        $toStatus = $to instanceof PolicyStatus ? $to : PolicyStatus::from($to);
        $fromStatus = $policy->status instanceof PolicyStatus ? $policy->status : PolicyStatus::from($policy->status);

        if (! $this->canTransition($fromStatus, $toStatus)) {
            throw new InvalidTransitionException($fromStatus, $toStatus);
        }

        // Update the policy model
        $policy->status = $toStatus;
        $policy->save();

        // Record the transition in the audit history
        $policy->stateHistory()->create([
            'from_status' => $fromStatus->value,
            'to_status'   => $toStatus->value,
            'reason'      => $reason,
        ]);

        return $policy;
    }
}
