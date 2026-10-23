<?php

namespace Tests\Unit;

use App\Enums\Destination;
use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use App\Services\PolicyStateMachine;
use App\Services\ProviderCallbackService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProviderCallbackServiceTest extends TestCase
{
    private ProviderCallbackService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProviderCallbackService(new PolicyStateMachine());
    }

    private function requireDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database not available: ' . $e->getMessage());
        }
    }

    private function createPolicy(PolicyStatus $status, string $ref, ?string $polNo = null): PolicyRequest
    {
        $policy = PolicyRequest::create([
            'client_request_id'  => 'req-' . uniqid(),
            'agent_id'           => 'agent-test',
            'customer_name'      => 'Alice Doe',
            'date_of_birth'      => '1992-03-10',
            'destination'        => Destination::Domestic,
            'trip_start'         => '2026-12-10',
            'trip_end'           => '2026-12-12',
            'travel_days'        => 3,
            'plan_code'          => 'BASIC',
            'sum_insured'        => 100000,
            'premium_amount'     => '36.00',
            'premium_breakdown'  => ['base_daily_rate' => '12'],
            'status'             => $status,
            'attempt_count'      => 1,
            'provider_reference' => $ref,
            'policy_number'      => $polNo,
        ]);

        $policy->stateHistory()->create([
            'from_status' => null,
            'to_status'   => $status->value,
            'reason'      => 'Initial test state.',
        ]);

        return $policy;
    }

    // ── 1. Unknown provider_reference returns 404 ─────────────────────────────

    public function test_unknown_provider_reference_returns_404(): void
    {
        $this->requireDatabase();

        $response = $this->service->handle([
            'provider_event_id'  => 'EVT-' . uniqid(),
            'provider_reference' => 'REF-UNKNOWN-999',
            'policy_number'      => 'POL-UNKNOWN',
            'event_type'         => 'POLICY_ISSUED',
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Unknown provider_reference.', $response->getData()->error);
    }

    // ── 2. First-seen success moves PENDING_CONFIRMATION -> ISSUED ─────────────

    public function test_first_seen_success_event_moves_pending_confirmation_to_issued(): void
    {
        $this->requireDatabase();

        $ref = 'REF-' . uniqid();
        $policy = $this->createPolicy(PolicyStatus::PendingConfirmation, $ref);

        $eventId = 'EVT-' . uniqid();
        $newPolNo = 'POL-' . uniqid();

        $response = $this->service->handle([
            'provider_event_id'  => $eventId,
            'provider_reference' => $ref,
            'policy_number'      => $newPolNo,
            'event_type'         => 'POLICY_ISSUED',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('processed', $response->getData()->status);

        // Policy is now ISSUED with the callback's policy_number
        $policy->refresh();
        $this->assertSame(PolicyStatus::Issued, $policy->status);
        $this->assertSame($newPolNo, $policy->policy_number);

        // provider_events row recorded and marked processed
        $event = ProviderEvent::where('provider_event_id', $eventId)->first();
        $this->assertNotNull($event);
        $this->assertNotNull($event->processed_at);
        $this->assertSame($policy->id, $event->policy_request_id);
    }

    // ── 3. First-seen success moves NEEDS_RECONCILIATION -> ISSUED ────────────

    public function test_first_seen_success_event_moves_needs_reconciliation_to_issued(): void
    {
        $this->requireDatabase();

        $ref = 'REF-' . uniqid();
        $policy = $this->createPolicy(PolicyStatus::NeedsReconciliation, $ref);

        $eventId = 'EVT-' . uniqid();
        $newPolNo = 'POL-' . uniqid();

        $response = $this->service->handle([
            'provider_event_id'  => $eventId,
            'provider_reference' => $ref,
            'policy_number'      => $newPolNo,
            'event_type'         => 'POLICY_ISSUED',
        ]);

        $this->assertSame(200, $response->getStatusCode());

        $policy->refresh();
        $this->assertSame(PolicyStatus::Issued, $policy->status);
        $this->assertSame($newPolNo, $policy->policy_number);
    }

    // ── 4. Duplicate provider_event_id returns 200 "already processed" ─────────

    public function test_duplicate_provider_event_id_returns_already_processed_without_reapplying(): void
    {
        $this->requireDatabase();

        $ref = 'REF-' . uniqid();
        $policy = $this->createPolicy(PolicyStatus::PendingConfirmation, $ref);

        $eventId = 'EVT-' . uniqid();
        $polNo = 'POL-' . uniqid();

        $payload = [
            'provider_event_id'  => $eventId,
            'provider_reference' => $ref,
            'policy_number'      => $polNo,
            'event_type'         => 'POLICY_ISSUED',
        ];

        // 1st time: processed
        $res1 = $this->service->handle($payload);
        $this->assertSame('processed', $res1->getData()->status);

        $historyCountAfterFirst = $policy->stateHistory()->count();

        // 2nd time with SAME provider_event_id: returns 200 "already processed"
        $res2 = $this->service->handle($payload);
        $this->assertSame(200, $res2->getStatusCode());
        $this->assertSame('already processed', $res2->getData()->status);
        $this->assertStringContainsString('already processed', strtolower($res2->getData()->message));

        // State history was NOT reapplied
        $this->assertSame($historyCountAfterFirst, $policy->stateHistory()->count());

        // Exactly 1 row in provider_events
        $count = ProviderEvent::where('provider_event_id', $eventId)->count();
        $this->assertSame(1, $count);
    }

    // ── 5. Late or repeated event never overwrites an ISSUED request ──────────

    public function test_event_arriving_for_issued_policy_is_stored_but_never_overwrites_policy_number(): void
    {
        $this->requireDatabase();

        $ref = 'REF-' . uniqid();
        $originalPolNo = 'POL-ORIGINAL-123';
        $policy = $this->createPolicy(PolicyStatus::Issued, $ref, $originalPolNo);

        $eventId = 'EVT-' . uniqid();
        $attemptedOverridePolNo = 'POL-ATTEMPTED-OVERWRITE-999';

        $response = $this->service->handle([
            'provider_event_id'  => $eventId,
            'provider_reference' => $ref,
            'policy_number'      => $attemptedOverridePolNo,
            'event_type'         => 'POLICY_ISSUED',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ignored', $response->getData()->status);

        // Policy number MUST NOT be overwritten
        $policy->refresh();
        $this->assertSame($originalPolNo, $policy->policy_number);
        $this->assertSame(PolicyStatus::Issued, $policy->status);

        // Event was stored
        $stored = ProviderEvent::where('provider_event_id', $eventId)->first();
        $this->assertNotNull($stored);
    }

    // ── 6. Event arriving for terminal state FAILED is stored but ignored ─────

    public function test_event_arriving_for_failed_policy_is_stored_but_ignored(): void
    {
        $this->requireDatabase();

        $ref = 'REF-' . uniqid();
        $policy = $this->createPolicy(PolicyStatus::Failed, $ref);

        $eventId = 'EVT-' . uniqid();

        $response = $this->service->handle([
            'provider_event_id'  => $eventId,
            'provider_reference' => $ref,
            'policy_number'      => 'POL-IGNORED',
            'event_type'         => 'POLICY_ISSUED',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ignored', $response->getData()->status);

        $policy->refresh();
        $this->assertSame(PolicyStatus::Failed, $policy->status);
        $this->assertNull($policy->policy_number);

        // Event was stored
        $this->assertNotNull(ProviderEvent::where('provider_event_id', $eventId)->first());
    }

    // ── 7. Failure event moves PENDING_CONFIRMATION -> FAILED ─────────────────

    public function test_failure_event_moves_pending_confirmation_to_failed(): void
    {
        $this->requireDatabase();

        $ref = 'REF-' . uniqid();
        $policy = $this->createPolicy(PolicyStatus::PendingConfirmation, $ref);

        $eventId = 'EVT-' . uniqid();

        $response = $this->service->handle([
            'provider_event_id'  => $eventId,
            'provider_reference' => $ref,
            'policy_number'      => 'POL-FAILED',
            'event_type'         => 'POLICY_FAILED',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('processed', $response->getData()->status);

        $policy->refresh();
        $this->assertSame(PolicyStatus::Failed, $policy->status);
    }
}
