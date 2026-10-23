<?php

namespace App\Services;

use App\Contracts\InsurerClient;
use App\Enums\IssuanceOutcome;
use App\Enums\PolicyStatus;
use App\Exceptions\InsurerTimeoutException;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use DomainException;
use Illuminate\Support\Facades\DB;

class IssuanceService
{
    public function __construct(
        private readonly InsurerClient      $insurer,
        private readonly PolicyStateMachine $stateMachine,
    ) {}

    /**
     * Submit a policy request for insurer issuance.
     *
     * Implements strict concurrency control via lockForUpdate inside a DB transaction,
     * ensuring that repeated or concurrent HTTP calls never cause duplicate provider
     * issuance calls.
     *
     * @param  string       $policyRequestId
     * @param  string|null  $scenario Dev-only simulation scenario override.
     * @return PolicyRequest
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If policy request does not exist.
     * @throws DomainException If policy request status is not submittable.
     */
    public function submit(string $policyRequestId, ?string $scenario = null): PolicyRequest
    {
        return DB::transaction(function () use ($policyRequestId, $scenario): PolicyRequest {

            // ── 1. Lock the row for update ────────────────────────────────────
            /** @var PolicyRequest $policy */
            $policy = PolicyRequest::where('id', $policyRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            // ── 2. If ISSUED: return immediately without calling provider ────
            if ($policy->status === PolicyStatus::Issued) {
                $policy->load(['stateHistory', 'issuanceAttempts']);
                return $policy;
            }

            // ── 3. If PENDING_CONFIRMATION or NEEDS_RECONCILIATION: do NOT re-issue; lookup instead ──
            if ($policy->status === PolicyStatus::PendingConfirmation || $policy->status === PolicyStatus::NeedsReconciliation) {
                $this->reconcileViaLookup($policy);
                $policy->load(['stateHistory', 'issuanceAttempts']);
                return $policy;
            }

            // ── 4. Verify status is VALIDATED ────────────────────────────────
            if ($policy->status !== PolicyStatus::Validated) {
                throw new DomainException(sprintf(
                    'Cannot submit policy request with status %s. Only VALIDATED requests can be submitted.',
                    $policy->status->value
                ));
            }

            // ── 5. Move to SUBMITTED ──────────────────────────────────────────
            $this->stateMachine->transition(
                $policy,
                PolicyStatus::Submitted,
                'Submitting policy request to insurer.'
            );

            // ── 6. Increment attempt_count ────────────────────────────────────
            $policy->attempt_count = $policy->attempt_count + 1;
            $policy->save();

            // ── 7. Create issuance_attempts row ───────────────────────────────
            /** @var IssuanceAttempt $attempt */
            $attempt = $policy->issuanceAttempts()->create([
                'attempt_no' => $policy->attempt_count,
                'outcome'    => IssuanceOutcome::Pending,
                'response'   => null,
                'error'      => null,
            ]);

            // ── 8. Call provider with idempotency_key = request id ───────────
            $payload = [
                'policy_request_id' => $policy->id,
                'agent_id'          => $policy->agent_id,
                'customer_name'     => $policy->customer_name,
                'date_of_birth'     => $policy->date_of_birth->toDateString(),
                'destination'       => $policy->destination->value,
                'trip_start'        => $policy->trip_start->toDateString(),
                'trip_end'          => $policy->trip_end->toDateString(),
                'travel_days'       => $policy->travel_days,
                'plan_code'         => $policy->plan_code,
                'sum_insured'       => (int) $policy->sum_insured,
                'premium_amount'    => $policy->premium_amount,
            ];

            try {
                $response = $this->insurer->issue($policy->id, $payload, $scenario);

                if ($response->success && $response->status === 'ISSUED') {
                    // Success -> ISSUED with policy_number and provider_reference
                    $attempt->update([
                        'outcome'  => IssuanceOutcome::Success,
                        'response' => $response->rawResponse,
                    ]);

                    $policy->policy_number          = $response->policyNumber;
                    $policy->provider_reference     = $response->providerReference;
                    $policy->last_provider_response = $response->rawResponse;
                    $policy->last_error             = null;
                    $policy->save();

                    $this->stateMachine->transition(
                        $policy,
                        PolicyStatus::Issued,
                        'Policy successfully issued by insurer.'
                    );

                } elseif ($response->status === 'PENDING') {
                    // Pending -> PENDING_CONFIRMATION
                    $attempt->update([
                        'outcome'  => IssuanceOutcome::Pending,
                        'response' => $response->rawResponse,
                    ]);

                    $policy->provider_reference     = $response->providerReference;
                    $policy->last_provider_response = $response->rawResponse;
                    $policy->save();

                    $this->stateMachine->transition(
                        $policy,
                        PolicyStatus::PendingConfirmation,
                        'Provider acknowledged request; pending confirmation.'
                    );

                } else {
                    // Retriable failure -> record error and return to VALIDATED (premium snapshot untouched)
                    $errorReason = $response->error ?? 'Provider reported a transient error.';
                    $attempt->update([
                        'outcome'  => IssuanceOutcome::RetryableFailure,
                        'error'    => $errorReason,
                        'response' => $response->rawResponse,
                    ]);

                    $policy->last_error             = $errorReason;
                    $policy->last_provider_response = $response->rawResponse;
                    $policy->save();

                    $this->stateMachine->transition(
                        $policy,
                        PolicyStatus::Validated,
                        'Transient provider failure; returned to VALIDATED for retry.'
                    );
                }

            } catch (InsurerTimeoutException $e) {
                // Timeout -> NEEDS_RECONCILIATION, then immediately try a status lookup and resolve to ISSUED if the provider has the policy
                $attempt->update([
                    'outcome' => IssuanceOutcome::Timeout,
                    'error'   => $e->getMessage(),
                ]);

                $policy->last_error = $e->getMessage();
                $policy->save();

                $this->stateMachine->transition(
                    $policy,
                    PolicyStatus::NeedsReconciliation,
                    'Provider timed out during issuance; placed in reconciliation.'
                );

                // Immediately try a status lookup to check if provider issued it
                $this->reconcileViaLookup($policy, $attempt);

            } catch (\Throwable $e) {
                // Transient error / network issue -> return to VALIDATED for retry
                $attempt->update([
                    'outcome' => IssuanceOutcome::RetryableFailure,
                    'error'   => $e->getMessage(),
                ]);

                $policy->last_error = $e->getMessage();
                $policy->save();

                $this->stateMachine->transition(
                    $policy,
                    PolicyStatus::Validated,
                    'Unexpected error during provider call; returned to VALIDATED for retry.'
                );
            }

            $policy->load(['stateHistory', 'issuanceAttempts']);

            return $policy;
        });
    }

    /**
     * Resolve PENDING_CONFIRMATION or NEEDS_RECONCILIATION via status lookup.
     */
    private function reconcileViaLookup(PolicyRequest $policy, ?IssuanceAttempt $attempt = null): void
    {
        try {
            $lookup = $this->insurer->lookup($policy->id);

            if ($lookup !== null && $lookup->status === 'ISSUED' && $lookup->policyNumber !== null) {
                $policy->policy_number          = $lookup->policyNumber;
                $policy->provider_reference     = $lookup->providerReference;
                $policy->last_provider_response = $lookup->rawResponse;
                $policy->last_error             = null;
                $policy->save();

                $this->stateMachine->transition(
                    $policy,
                    PolicyStatus::Issued,
                    'Resolved to ISSUED via provider status lookup.'
                );

                $attempt?->update([
                    'outcome'  => IssuanceOutcome::Success,
                    'response' => $lookup->rawResponse,
                ]);

            } elseif ($lookup !== null && $lookup->status === 'FAILED') {
                $policy->last_error             = $lookup->error ?? 'Provider reported failure.';
                $policy->last_provider_response = $lookup->rawResponse;
                $policy->save();

                $this->stateMachine->transition(
                    $policy,
                    PolicyStatus::Failed,
                    'Resolved to FAILED via provider status lookup.'
                );

                $attempt?->update([
                    'outcome' => IssuanceOutcome::Failed,
                    'error'   => $policy->last_error,
                ]);
            }
        } catch (\Throwable) {
            // Lookup failed / still unreachable; policy remains in current state
        }
    }
}
