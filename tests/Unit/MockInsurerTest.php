<?php

namespace Tests\Unit;

use App\Enums\MockInsurerScenario;
use App\Exceptions\InsurerTimeoutException;
use App\Models\MockPolicy;
use App\Services\Insurer\MockInsurer;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MockInsurerTest extends TestCase
{
    private MockInsurer $insurer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->insurer = new MockInsurer();
    }

    private function requireDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database connection not reachable: ' . $e->getMessage());
        }
    }

    // ── 1. Deterministic Value Generation ─────────────────────────────────────

    public function test_policy_numbers_are_deterministic(): void
    {
        $key1 = 'req-abc-123';
        $key2 = 'req-xyz-789';

        $pol1a = MockInsurer::generatePolicyNumber($key1);
        $pol1b = MockInsurer::generatePolicyNumber($key1);
        $pol2  = MockInsurer::generatePolicyNumber($key2);

        $this->assertSame($pol1a, $pol1b, 'Same idempotency key must yield identical policy number');
        $this->assertNotSame($pol1a, $pol2, 'Different idempotency keys must yield different policy numbers');
        $this->assertStringStartsWith('POL-', $pol1a);
    }

    public function test_provider_references_are_deterministic(): void
    {
        $key = 'req-ref-test';
        $ref1 = MockInsurer::generateProviderReference($key);
        $ref2 = MockInsurer::generateProviderReference($key);

        $this->assertSame($ref1, $ref2);
        $this->assertStringStartsWith('REF-', $ref1);
    }

    public function test_event_ids_are_deterministic(): void
    {
        $key = 'req-evt-test';
        $evt1 = MockInsurer::generateEventId($key, 1);
        $evt2 = MockInsurer::generateEventId($key, 1);

        $this->assertSame($evt1, $evt2);
        $this->assertStringStartsWith('EVT-', $evt1);
    }

    // ── 2. Scenario Resolution ────────────────────────────────────────────────

    public function test_resolves_default_scenario_to_success(): void
    {
        $this->assertSame(MockInsurerScenario::Success, $this->insurer->resolveScenario());
    }

    public function test_explicit_scenario_override_wins(): void
    {
        $scenario = $this->insurer->resolveScenario('TEMPORARY_FAILURE');
        $this->assertSame(MockInsurerScenario::TemporaryFailure, $scenario);

        $scenario2 = $this->insurer->resolveScenario('TIMEOUT_AFTER_ISSUE');
        $this->assertSame(MockInsurerScenario::TimeoutAfterIssue, $scenario2);
    }

    // ── 3. Scenario: TEMPORARY_FAILURE ───────────────────────────────────────

    public function test_temporary_failure_returns_retriable_error(): void
    {
        $this->requireDatabase();

        $key = 'req-temp-fail-' . uniqid();
        $response = $this->insurer->issue($key, [], 'TEMPORARY_FAILURE');

        $this->assertFalse($response->success);
        $this->assertSame('FAILED', $response->status);
        $this->assertTrue($response->isRetryable);
        $this->assertSame('TEMPORARY_SERVICE_UNAVAILABLE', $response->error);
    }

    // ── 4. DB-backed Scenarios: SUCCESS, TIMEOUT, DELAYED, DUPLICATE ──────────

    public function test_success_scenario_persists_and_returns_policy(): void
    {
        $this->requireDatabase();

        $key = 'req-success-' . uniqid();
        $response = $this->insurer->issue($key, ['name' => 'Alice'], 'SUCCESS');

        $this->assertTrue($response->success);
        $this->assertSame('ISSUED', $response->status);
        $this->assertNotNull($response->policyNumber);
        $this->assertNotNull($response->providerReference);

        $stored = MockPolicy::where('idempotency_key', $key)->first();
        $this->assertNotNull($stored);
        $this->assertSame($response->policyNumber, $stored->policy_number);
        $this->assertSame($response->providerReference, $stored->provider_reference);
        $this->assertSame('ISSUED', $stored->status);
    }

    public function test_mock_is_strictly_idempotent_per_idempotency_key(): void
    {
        $this->requireDatabase();

        $key = 'req-idem-' . uniqid();

        // First call
        $res1 = $this->insurer->issue($key, ['test' => 1], 'SUCCESS');
        // Second call with same key
        $res2 = $this->insurer->issue($key, ['test' => 2], 'SUCCESS');

        $this->assertSame($res1->policyNumber, $res2->policyNumber);
        $this->assertSame($res1->providerReference, $res2->providerReference);

        // Verify only 1 row exists in mock_policies
        $count = MockPolicy::where('idempotency_key', $key)->count();
        $this->assertSame(1, $count);
    }

    public function test_timeout_after_issue_creates_policy_then_throws_timeout_exception(): void
    {
        $this->requireDatabase();

        $key = 'req-timeout-' . uniqid();

        try {
            $this->insurer->issue($key, [], 'TIMEOUT_AFTER_ISSUE');
            $this->fail('Expected InsurerTimeoutException');
        } catch (InsurerTimeoutException $e) {
            $this->assertSame($key, $e->idempotencyKey);
        }

        // Verify the policy was actually persisted in mock_policies
        $stored = MockPolicy::where('idempotency_key', $key)->first();
        $this->assertNotNull($stored, 'Policy must be created in mock_policies despite timeout');
        $this->assertSame('ISSUED', $stored->status);
        $this->assertNotNull($stored->policy_number);

        // Subsequent status lookup returns the issued policy
        $lookup = $this->insurer->lookup($key);
        $this->assertNotNull($lookup);
        $this->assertSame($stored->policy_number, $lookup->policyNumber);
        $this->assertSame('ISSUED', $lookup->status);
    }

    public function test_delayed_callback_returns_pending_and_later_emits_success(): void
    {
        $this->requireDatabase();

        $key = 'req-delayed-' . uniqid();

        // 1. Issue returns PENDING
        $response = $this->insurer->issue($key, [], 'DELAYED_CALLBACK');
        $this->assertSame('PENDING', $response->status);

        // 2. Lookup reflects PENDING
        $lookup = $this->insurer->lookup($key);
        $this->assertNotNull($lookup);
        $this->assertSame('PENDING', $lookup->status);

        // 3. Trigger callback transitions policy to ISSUED and emits success event
        $events = $this->insurer->triggerCallback($key, 'DELAYED_CALLBACK');
        $this->assertCount(1, $events);
        $this->assertSame('POLICY_ISSUED', $events[0]['event_type']);
        $this->assertSame('ISSUED', $events[0]['status']);
        $this->assertNotNull($events[0]['policy_number']);

        // Policy is now ISSUED in database
        $stored = MockPolicy::where('idempotency_key', $key)->first();
        $this->assertSame('ISSUED', $stored->status);
    }

    public function test_duplicate_callback_emits_same_event_multiple_times(): void
    {
        $this->requireDatabase();

        $key = 'req-dup-cb-' . uniqid();
        $this->insurer->issue($key, [], 'SUCCESS');

        // Trigger with DUPLICATE_CALLBACK
        $events = $this->insurer->triggerCallback($key, 'DUPLICATE_CALLBACK');

        $this->assertCount(3, $events, 'DUPLICATE_CALLBACK should emit 3 events');
        // All events must have the EXACT SAME provider_event_id
        $this->assertSame($events[0]['provider_event_id'], $events[1]['provider_event_id']);
        $this->assertSame($events[0]['provider_event_id'], $events[2]['provider_event_id']);
        $this->assertSame($key, $events[0]['policy_request_id']);
    }
}
