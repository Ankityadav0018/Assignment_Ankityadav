<?php

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProviderCallbackService
{
    public function __construct(
        private readonly PolicyStateMachine $stateMachine,
    ) {}

    /**
     * Handle incoming provider callback event.
     *
     * Rules:
     *  - Unknown provider_reference returns 404.
     *  - Insert into provider_events in a transaction; UNIQUE(provider_event_id)
     *    detects duplicates and returns 200 with "already processed" without reapplying.
     *  - An event arriving for a terminal state (ISSUED, FAILED) is stored but ignored.
     *  - A late or repeated event must never overwrite an ISSUED request or change its policy_number.
     *  - Only a first-seen success event moves PENDING_CONFIRMATION / NEEDS_RECONCILIATION -> ISSUED.
     *
     * @param  array  $data  Validated callback payload.
     * @return JsonResponse
     */
    public function handle(array $data): JsonResponse
    {
        try {
            return DB::transaction(function () use ($data): JsonResponse {

                // 1. Find policy request by provider_reference
                /** @var PolicyRequest|null $policy */
                $policy = PolicyRequest::where('provider_reference', $data['provider_reference'])
                    ->lockForUpdate()
                    ->first();

                if ($policy === null) {
                    return response()->json([
                        'error' => 'Unknown provider_reference.',
                    ], 404);
                }

                // 2. Insert into provider_events (relying on UNIQUE(provider_event_id))
                $event = ProviderEvent::create([
                    'provider_event_id' => $data['provider_event_id'],
                    'policy_request_id' => $policy->id,
                    'payload'           => $data,
                    'processed_at'      => null,
                ]);

                // 3. If policy is in terminal state: event is stored but ignored
                // "an event arriving for a terminal state is stored but ignored."
                // "A late or repeated event must never overwrite an ISSUED request or change its policy_number."
                if ($policy->isTerminal()) {
                    $event->markProcessed();

                    return response()->json([
                        'status'  => 'ignored',
                        'message' => 'Policy is in terminal state; event stored and ignored.',
                    ], 200);
                }

                // 4. Check if first-seen success event can transition PENDING_CONFIRMATION or NEEDS_RECONCILIATION -> ISSUED
                $eventType = strtoupper(trim((string) $data['event_type']));
                $isSuccessEvent = in_array($eventType, ['POLICY_ISSUED', 'ISSUED', 'SUCCESS'], true);

                $isFailureEvent = in_array($eventType, ['POLICY_FAILED', 'FAILED', 'REJECTED'], true);

                $eligibleForIssuance = (
                    $policy->status === PolicyStatus::PendingConfirmation ||
                    $policy->status === PolicyStatus::NeedsReconciliation
                );

                if ($isSuccessEvent && $eligibleForIssuance) {
                    $policy->policy_number = $data['policy_number'];
                    $policy->save();

                    $this->stateMachine->transition(
                        $policy,
                        PolicyStatus::Issued,
                        'Confirmed and issued via provider callback event.'
                    );
                } elseif ($isFailureEvent && $eligibleForIssuance) {
                    $policy->last_error = $data['error'] ?? 'Provider callback reported failure.';
                    $policy->save();

                    $this->stateMachine->transition(
                        $policy,
                        PolicyStatus::Failed,
                        'Failed via provider callback event.'
                    );
                } elseif (! $isSuccessEvent && ! $isFailureEvent) {
                    $event->markProcessed();

                    return response()->json([
                        'status'  => 'ignored',
                        'message' => 'Unknown event_type; event stored and ignored.',
                    ], 200);
                }

                $event->markProcessed();

                return response()->json([
                    'status'  => 'processed',
                    'message' => 'Event processed successfully.',
                ], 200);
            });

        } catch (UniqueConstraintViolationException) {
            // Duplicate provider_event_id caught via DB unique index
            return response()->json([
                'status'  => 'already processed',
                'message' => 'Event already processed.',
            ], 200);
        }
    }
}
