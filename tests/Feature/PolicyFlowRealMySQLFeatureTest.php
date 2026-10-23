<?php

namespace Tests\Feature;

use App\Contracts\InsurerClient;
use App\Enums\PolicyStatus;
use App\Models\MockPolicy;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Feature tests covering end-to-end integration against the MySQL database:
 *
 * 1.  Valid intake returns 201 with premium 225.00 and status VALIDATED.
 * 2.  Invalid dates -> 422.
 * 3.  Unsupported plan -> 422.
 * 4.  Repeated agent_id + client_request_id returns 200 and DB count stays 1.
 * 5.  Same client_request_id with a different agent_id creates a new request.
 * 6.  Submit SUCCESS yields exactly one policy_number.
 * 7.  TEMPORARY_FAILURE then retry yields one policy and the same premium.
 * 8.  TIMEOUT_AFTER_ISSUE resolves via status lookup with exactly one mock_policies row.
 * 9.  DUPLICATE_CALLBACK applies once (provider_events count 1, history has a single ISSUED transition).
 * 10. A late callback cannot overwrite ISSUED.
 * 11. Repeated submit on ISSUED makes zero provider calls (assert via spy).
 * 12. Concurrency test fires parallel inserts with the same key and asserts exactly one row.
 */
class PolicyFlowRealMySQLFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Real MySQL test database not reachable (configured in phpunit.xml): ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper to build a canonical valid payload for a 28-year-old traveler,
     * PLUS plan, international destination, 5 days, 300k sum insured = 225.00.
     */
    private function validPayload(array $overrides = []): array
    {
        $tripStart = '2024-06-01';
        $tripEnd   = '2024-06-05'; // 5 days inclusive

        // Exactly age 28 on trip_start (born 1996-06-01)
        $dob = CarbonImmutable::parse($tripStart)->subYears(28)->toDateString();

        return array_merge([
            'client_request_id' => 'req-' . uniqid(),
            'agent_id'          => 'agent-' . uniqid(),
            'customer_name'     => 'Alice Smith',
            'date_of_birth'     => $dob,
            'destination'       => 'INTERNATIONAL',
            'trip_start'        => $tripStart,
            'trip_end'          => $tripEnd,
            'plan_code'         => 'PLUS',
            'sum_insured'       => 300000,
        ], $overrides);
    }

    // ── 1. Valid intake returns 201 with premium 225.00 and status VALIDATED ──

    public function test_valid_intake_returns_201_with_premium_225_and_status_validated(): void
    {
        $payload = $this->validPayload();

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', PolicyStatus::Validated->value)
            ->assertJsonPath('data.premium_amount', '225.00')
            ->assertJsonPath('data.travel_days', 5)
            ->assertJsonPath('data.plan_code', 'PLUS')
            ->assertJsonPath('data.sum_insured', 300000);

        $id = $response->json('data.id');
        $this->assertNotNull($id);

        // Verify DB row
        $policy = PolicyRequest::findOrFail($id);
        $this->assertSame(PolicyStatus::Validated, $policy->status);
        $this->assertSame('225.00', $policy->premium_amount);
        $this->assertSame(0, $policy->attempt_count);

        // Verify state history: RECEIVED then VALIDATED
        $history = $policy->stateHistory()->get();
        $this->assertCount(2, $history);
        $this->assertSame(PolicyStatus::Received->value, $history[0]->to_status);
        $this->assertSame(PolicyStatus::Validated->value, $history[1]->to_status);
    }

    // ── 2. Invalid dates -> 422 ───────────────────────────────────────────────

    public function test_invalid_dates_return_422(): void
    {
        // 2a. trip_end before trip_start
        $payload1 = $this->validPayload([
            'trip_start' => '2024-06-10',
            'trip_end'   => '2024-06-05',
        ]);
        $res1 = $this->postJson('/api/policy-requests', $payload1);
        $res1->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);

        // 2b. date_of_birth in the future
        $payload2 = $this->validPayload([
            'date_of_birth' => CarbonImmutable::tomorrow()->toDateString(),
        ]);
        $res2 = $this->postJson('/api/policy-requests', $payload2);
        $res2->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    // ── 3. Unsupported plan -> 422 ───────────────────────────────────────────

    public function test_unsupported_plan_returns_422(): void
    {
        $payload = $this->validPayload([
            'plan_code' => 'PLATINUM_VIP',
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code']);
    }

    // ── 4. Repeated agent_id+client_request_id returns 200 and DB count stays 1

    public function test_repeated_agent_and_client_request_id_returns_200_and_db_count_stays_one(): void
    {
        $payload = $this->validPayload();

        // 1st request -> 201 Created
        $res1 = $this->postJson('/api/policy-requests', $payload);
        $res1->assertStatus(201);
        $id1 = $res1->json('data.id');

        // 2nd request with identical agent_id + client_request_id -> 200 OK
        $res2 = $this->postJson('/api/policy-requests', $payload);
        $res2->assertStatus(200);
        $this->assertSame($id1, $res2->json('data.id'));

        // DB count must be exactly 1
        $count = PolicyRequest::where('agent_id', $payload['agent_id'])
            ->where('client_request_id', $payload['client_request_id'])
            ->count();

        $this->assertSame(1, $count);
    }

    // ── 5. Same client_request_id with a different agent_id creates a new request

    public function test_same_client_request_id_with_different_agent_creates_new_request(): void
    {
        $sharedRequestId = 'req-shared-' . uniqid();

        $payloadA = $this->validPayload([
            'agent_id'          => 'agent-ALPHA-' . uniqid(),
            'client_request_id' => $sharedRequestId,
        ]);
        $resA = $this->postJson('/api/policy-requests', $payloadA);
        $resA->assertStatus(201);
        $idA = $resA->json('data.id');

        $payloadB = $this->validPayload([
            'agent_id'          => 'agent-BETA-' . uniqid(),
            'client_request_id' => $sharedRequestId,
        ]);
        $resB = $this->postJson('/api/policy-requests', $payloadB);
        $resB->assertStatus(201);
        $idB = $resB->json('data.id');

        // Must be separate rows
        $this->assertNotSame($idA, $idB);

        $totalWithKey = PolicyRequest::where('client_request_id', $sharedRequestId)->count();
        $this->assertSame(2, $totalWithKey);
    }

    // ── 6. Submit SUCCESS yields exactly one policy_number ────────────────────

    public function test_submit_success_yields_exactly_one_policy_number(): void
    {
        $payload = $this->validPayload();
        $createRes = $this->postJson('/api/policy-requests', $payload);
        $createRes->assertStatus(201);
        $id = $createRes->json('data.id');

        // Submit with default SUCCESS scenario
        $submitRes = $this->postJson("/api/policy-requests/{$id}/submit");
        $submitRes->assertStatus(200)
            ->assertJsonPath('data.status', PolicyStatus::Issued->value);

        $policyNumber = $submitRes->json('data.policy_number');
        $this->assertNotNull($policyNumber);
        $this->assertStringStartsWith('POL-', $policyNumber);

        // Verify database state
        $policy = PolicyRequest::findOrFail($id);
        $this->assertSame(PolicyStatus::Issued, $policy->status);
        $this->assertSame($policyNumber, $policy->policy_number);
        $this->assertSame(1, $policy->attempt_count);

        // Exactly one issuance attempt
        $this->assertSame(1, $policy->issuanceAttempts()->count());
    }

    // ── 7. TEMPORARY_FAILURE then retry yields one policy and the same premium ─

    public function test_temporary_failure_then_retry_yields_one_policy_and_the_same_premium(): void
    {
        $payload = $this->validPayload();
        $createRes = $this->postJson('/api/policy-requests', $payload);
        $id = $createRes->json('data.id');

        // 1st submit: TEMPORARY_FAILURE -> 503 Service Unavailable
        $submit1 = $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'TEMPORARY_FAILURE',
        ]);
        $submit1->assertStatus(503)
            ->assertJsonPath('data.status', PolicyStatus::Validated->value)
            ->assertJsonPath('data.premium_amount', '225.00');

        $policyAfterFail = PolicyRequest::findOrFail($id);
        $this->assertSame(PolicyStatus::Validated, $policyAfterFail->status);
        $this->assertSame(1, $policyAfterFail->attempt_count);
        $this->assertSame('225.00', $policyAfterFail->premium_amount);
        $this->assertNull($policyAfterFail->policy_number);

        // 2nd submit (retry): SUCCESS -> 200 OK
        $submit2 = $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'SUCCESS',
        ]);
        $submit2->assertStatus(200)
            ->assertJsonPath('data.status', PolicyStatus::Issued->value)
            ->assertJsonPath('data.premium_amount', '225.00');

        $policyAfterSuccess = PolicyRequest::findOrFail($id);
        $this->assertSame(PolicyStatus::Issued, $policyAfterSuccess->status);
        $this->assertNotNull($policyAfterSuccess->policy_number);
        $this->assertSame('225.00', $policyAfterSuccess->premium_amount);
        $this->assertSame(2, $policyAfterSuccess->attempt_count);
    }

    // ── 8. TIMEOUT_AFTER_ISSUE resolves via lookup with exactly one mock row ──

    public function test_timeout_after_issue_resolves_via_status_lookup_with_one_mock_policies_row(): void
    {
        $payload = $this->validPayload();
        $createRes = $this->postJson('/api/policy-requests', $payload);
        $id = $createRes->json('data.id');

        // Submit with TIMEOUT_AFTER_ISSUE: mock creates row, throws timeout, service immediately reconciles via lookup
        $submitRes = $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);
        $submitRes->assertStatus(200)
            ->assertJsonPath('data.status', PolicyStatus::Issued->value);

        // Exactly ONE mock_policies row exists for this policy
        $mockCount = MockPolicy::where('idempotency_key', $id)->count();
        $this->assertSame(1, $mockCount);

        $policy = PolicyRequest::findOrFail($id);
        $this->assertSame(PolicyStatus::Issued, $policy->status);
        $this->assertNotNull($policy->policy_number);
    }

    // ── 9. DUPLICATE_CALLBACK applies once ────────────────────────────────────

    public function test_duplicate_callback_applies_once_and_has_single_issued_transition(): void
    {
        $payload = $this->validPayload();
        $createRes = $this->postJson('/api/policy-requests', $payload);
        $id = $createRes->json('data.id');

        // Submit with DELAYED_CALLBACK -> 202 PENDING_CONFIRMATION
        $submitRes = $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'DELAYED_CALLBACK',
        ]);
        $submitRes->assertStatus(202)
            ->assertJsonPath('data.status', PolicyStatus::PendingConfirmation->value);

        $ref = $submitRes->json('data.provider_reference');
        $this->assertNotNull($ref);

        $eventId = 'EVT-DUP-' . uniqid();
        $polNo   = 'POL-CALLBACK-' . uniqid();

        $callbackPayload = [
            'provider_event_id'  => $eventId,
            'provider_reference' => $ref,
            'policy_number'      => $polNo,
            'event_type'         => 'POLICY_ISSUED',
        ];

        // 1st callback arrival: processed -> moves to ISSUED
        $cb1 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cb1->assertStatus(200)
            ->assertJsonPath('status', 'processed');

        $policy = PolicyRequest::findOrFail($id);
        $this->assertSame(PolicyStatus::Issued, $policy->status);
        $this->assertSame($polNo, $policy->policy_number);

        // 2nd callback arrival with the EXACT same provider_event_id: already processed
        $cb2 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cb2->assertStatus(200)
            ->assertJsonPath('status', 'already processed');

        // 3rd callback arrival with the EXACT same provider_event_id: already processed
        $cb3 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cb3->assertStatus(200)
            ->assertJsonPath('status', 'already processed');

        // Assert provider_events count is exactly 1
        $eventCount = ProviderEvent::where('provider_event_id', $eventId)->count();
        $this->assertSame(1, $eventCount);

        // Assert policy_state_history has a single ISSUED transition
        $issuedCount = $policy->stateHistory()->where('to_status', PolicyStatus::Issued->value)->count();
        $this->assertSame(1, $issuedCount);
    }

    // ── 10. A late callback cannot overwrite ISSUED ───────────────────────────

    public function test_late_callback_cannot_overwrite_issued(): void
    {
        $payload = $this->validPayload();
        $createRes = $this->postJson('/api/policy-requests', $payload);
        $id = $createRes->json('data.id');

        // Issue directly via submit
        $submitRes = $this->postJson("/api/policy-requests/{$id}/submit");
        $submitRes->assertStatus(200);

        $originalPolNo = $submitRes->json('data.policy_number');
        $ref           = $submitRes->json('data.provider_reference');
        $this->assertNotNull($originalPolNo);

        // A late callback arrives with a different policy_number and different event_id
        $lateCallback = [
            'provider_event_id'  => 'EVT-LATE-' . uniqid(),
            'provider_reference' => $ref,
            'policy_number'      => 'POL-ROGUE-OVERWRITE-999',
            'event_type'         => 'POLICY_ISSUED',
        ];

        $cbRes = $this->postJson('/api/mock-insurer/callback', $lateCallback);
        $cbRes->assertStatus(200)
            ->assertJsonPath('status', 'ignored');

        // Policy in DB must preserve its original policy_number
        $policy = PolicyRequest::findOrFail($id);
        $this->assertSame(PolicyStatus::Issued, $policy->status);
        $this->assertSame($originalPolNo, $policy->policy_number);
    }

    // ── 11. Repeated submit on ISSUED makes zero provider calls ───────────────

    public function test_repeated_submit_on_issued_makes_zero_provider_calls(): void
    {
        $payload = $this->validPayload();
        $createRes = $this->postJson('/api/policy-requests', $payload);
        $id = $createRes->json('data.id');

        // First submit: issues policy
        $this->postJson("/api/policy-requests/{$id}/submit")->assertStatus(200);

        $policy = PolicyRequest::findOrFail($id);
        $this->assertSame(PolicyStatus::Issued, $policy->status);

        // Install spy for InsurerClient to assert zero calls on subsequent submit
        $spy = Mockery::spy(InsurerClient::class);
        $this->app->instance(InsurerClient::class, $spy);

        $repeatRes = $this->postJson("/api/policy-requests/{$id}/submit");
        $repeatRes->assertStatus(200)
            ->assertJsonPath('data.status', PolicyStatus::Issued->value);

        // Verify zero provider calls were made
        $spy->shouldNotHaveReceived('issue');
        $spy->shouldNotHaveReceived('lookup');
        $spy->shouldNotHaveReceived('triggerCallback');
    }

    // ── 12. Concurrency test: parallel inserts with same key produce 1 row ────

    public function test_concurrent_inserts_with_same_key_produces_exactly_one_row(): void
    {
        $agentId         = 'agent-race-' . uniqid();
        $clientRequestId = 'req-race-' . uniqid();

        $payload = $this->validPayload([
            'agent_id'          => $agentId,
            'client_request_id' => $clientRequestId,
        ]);

        if (function_exists('pcntl_fork')) {
            // Disconnect parent DB connection so socket is not shared
            DB::disconnect();

            $forkCount = 4;
            $pids = [];

            for ($i = 0; $i < $forkCount; $i++) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    $this->fail('Failed to fork process');
                } elseif ($pid === 0) {
                    // Child process: connect to DB and execute POST
                    DB::reconnect();

                    try {
                        $res = $this->postJson('/api/policy-requests', $payload);
                        $status = $res->getStatusCode();
                        // Valid responses: 201 (created winner) or 200 (duplicate caught)
                        exit(($status === 201 || $status === 200) ? 0 : 1);
                    } catch (\Throwable) {
                        exit(1);
                    }
                } else {
                    $pids[] = $pid;
                }
            }

            // Wait for all child processes
            $exitCodes = [];
            foreach ($pids as $pid) {
                $status = 0;
                pcntl_waitpid($pid, $status);
                $exitCodes[] = pcntl_wexitstatus($status);
            }

            // Reconnect DB in parent
            DB::reconnect();

            // All child processes must have succeeded
            foreach ($exitCodes as $code) {
                $this->assertSame(0, $code, 'Child process should have exited successfully with code 0 (200 or 201)');
            }
        } else {
            // Fallback to Concurrency facade when pcntl_fork is unavailable
            Concurrency::run([
                fn () => $this->postJson('/api/policy-requests', $payload),
                fn () => $this->postJson('/api/policy-requests', $payload),
                fn () => $this->postJson('/api/policy-requests', $payload),
            ]);
        }

        // Exactly ONE row must exist in the database
        $count = PolicyRequest::where('agent_id', $agentId)
            ->where('client_request_id', $clientRequestId)
            ->count();

        $this->assertSame(1, $count, 'Concurrent inserts must result in exactly 1 row in policy_requests');
    }
}
