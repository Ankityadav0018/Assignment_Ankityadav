<?php

namespace Tests\Feature;

use App\Enums\Destination;
use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PolicyRequestApiTest extends TestCase
{
    private function validPayload(): array
    {
        return [
            'client_request_id' => 'req-' . uniqid(),
            'agent_id'          => 'agent-007',
            'customer_name'     => 'Alice Smith',
            'date_of_birth'     => '1998-04-12',
            'destination'       => 'INTERNATIONAL',
            'trip_start'        => '2026-11-01',
            'trip_end'          => '2026-11-05',
            'plan_code'         => 'PLUS',
            'sum_insured'       => 300000,
        ];
    }

    private function requireDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL test database not reachable (configured in phpunit.xml): ' . $e->getMessage());
        }
    }

    // ── Validation Tests (HTTP 422) ───────────────────────────────────────────

    public function test_store_fails_when_required_fields_are_missing(): void
    {
        $response = $this->postJson('/api/policy-requests', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'client_request_id',
                'agent_id',
                'customer_name',
                'date_of_birth',
                'destination',
                'trip_start',
                'trip_end',
                'plan_code',
                'sum_insured',
            ]);
    }

    public function test_store_fails_when_date_of_birth_is_today_or_future(): void
    {
        $payload = $this->validPayload();
        $payload['date_of_birth'] = CarbonImmutable::today()->toDateString();

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    public function test_store_fails_when_trip_end_is_before_trip_start(): void
    {
        $payload = $this->validPayload();
        $payload['trip_start'] = '2026-12-10';
        $payload['trip_end']   = '2026-12-05';

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);
    }

    public function test_store_fails_for_unsupported_plan_code(): void
    {
        $payload = $this->validPayload();
        $payload['plan_code'] = 'EXECUTIVE_PLUS';

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code']);
    }

    public function test_store_fails_for_unsupported_sum_insured(): void
    {
        $payload = $this->validPayload();
        $payload['sum_insured'] = 250000;

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sum_insured']);
    }

    public function test_store_fails_for_unsupported_destination(): void
    {
        $payload = $this->validPayload();
        $payload['destination'] = 'MOON';

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination']);
    }

    // ── Happy Path and Idempotency Tests (HTTP 201 & 200) ─────────────────────

    public function test_store_creates_policy_request_and_audit_history(): void
    {
        $this->requireDatabase();

        $payload = $this->validPayload();

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'agent_id',
                    'client_request_id',
                    'customer_name',
                    'date_of_birth',
                    'destination',
                    'trip_start',
                    'trip_end',
                    'travel_days',
                    'plan_code',
                    'sum_insured',
                    'premium_amount',
                    'premium_breakdown',
                    'status',
                    'attempt_count',
                    'provider_reference',
                    'policy_number',
                    'state_history',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $json = $response->json('data');
        $this->assertSame(PolicyStatus::Validated->value, $json['status']);
        $this->assertSame('225.00', $json['premium_amount']);
        $this->assertSame(5, $json['travel_days']);

        // Audit trail: verify both RECEIVED and VALIDATED transitions exist
        $history = $json['state_history'];
        $this->assertCount(2, $history);
        $this->assertNull($history[0]['from_status']);
        $this->assertSame(PolicyStatus::Received->value, $history[0]['to_status']);
        $this->assertSame(PolicyStatus::Received->value, $history[1]['from_status']);
        $this->assertSame(PolicyStatus::Validated->value, $history[1]['to_status']);
    }

    public function test_store_is_idempotent_on_duplicate_agent_and_client_request_id(): void
    {
        $this->requireDatabase();

        $payload = $this->validPayload();

        // First attempt -> 201
        $res1 = $this->postJson('/api/policy-requests', $payload);
        $res1->assertStatus(201);
        $id1 = $res1->json('data.id');

        // Repeat with identical agent_id + client_request_id -> 200 with the exact same record
        $res2 = $this->postJson('/api/policy-requests', $payload);
        $res2->assertStatus(200);
        $this->assertSame($id1, $res2->json('data.id'));
    }

    public function test_show_returns_policy_request_with_audit_trail(): void
    {
        $this->requireDatabase();

        $payload = $this->validPayload();
        $createRes = $this->postJson('/api/policy-requests', $payload);
        $createRes->assertStatus(201);
        $id = $createRes->json('data.id');

        $showRes = $this->getJson("/api/policy-requests/{$id}");
        $showRes->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', PolicyStatus::Validated->value)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'premium_amount',
                    'status',
                    'state_history',
                ],
            ]);
    }

    public function test_show_nonexistent_returns_404(): void
    {
        $this->requireDatabase();

        $response = $this->getJson('/api/policy-requests/00000000-0000-0000-0000-000000000000');
        $response->assertStatus(404);
    }

    public function test_index_lists_policy_requests_with_status_filter_and_pagination(): void
    {
        $this->requireDatabase();

        $payload = $this->validPayload();
        $this->postJson('/api/policy-requests', $payload)->assertStatus(201);

        // List all
        $listRes = $this->getJson('/api/policy-requests');
        $listRes->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'links',
                'meta',
            ]);

        // Filter by status
        $filterRes = $this->getJson('/api/policy-requests?status=VALIDATED');
        $filterRes->assertStatus(200);
        foreach ($filterRes->json('data') as $item) {
            $this->assertSame(PolicyStatus::Validated->value, $item['status']);
        }

        // Filter by non-matching status
        $emptyRes = $this->getJson('/api/policy-requests?status=ISSUED');
        $emptyRes->assertStatus(200);
        $this->assertCount(0, $emptyRes->json('data'));
    }

    // ── POST /api/policy-requests/{id}/submit Tests ───────────────────────────

    public function test_submit_nonexistent_returns_404(): void
    {
        $this->requireDatabase();

        $response = $this->postJson('/api/policy-requests/00000000-0000-0000-0000-000000000000/submit');
        $response->assertStatus(404);
    }

    public function test_submit_success_flow_issues_policy(): void
    {
        $this->requireDatabase();

        $payload = $this->validPayload();
        $createRes = $this->postJson('/api/policy-requests', $payload);
        $createRes->assertStatus(201);
        $id = $createRes->json('data.id');

        // Submit with default SUCCESS scenario
        $submitRes = $this->postJson("/api/policy-requests/{$id}/submit");
        $submitRes->assertStatus(200)
            ->assertJsonPath('data.status', PolicyStatus::Issued->value)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'policy_number',
                    'provider_reference',
                    'attempt_count',
                    'state_history',
                    'issuance_attempts',
                ],
            ]);

        $this->assertNotNull($submitRes->json('data.policy_number'));
        $this->assertNotNull($submitRes->json('data.provider_reference'));
        $this->assertSame(1, $submitRes->json('data.attempt_count'));

        // Repeated submit returns 200 without duplicate issuance calls
        $repeatRes = $this->postJson("/api/policy-requests/{$id}/submit");
        $repeatRes->assertStatus(200);
        $this->assertSame($submitRes->json('data.policy_number'), $repeatRes->json('data.policy_number'));
    }

    public function test_submit_temporary_failure_returns_503_and_returns_to_validated(): void
    {
        $this->requireDatabase();

        $payload = $this->validPayload();
        $createRes = $this->postJson('/api/policy-requests', $payload);
        $id = $createRes->json('data.id');

        $submitRes = $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'TEMPORARY_FAILURE',
        ]);

        $submitRes->assertStatus(503)
            ->assertJsonPath('data.status', PolicyStatus::Validated->value);

        // Premium snapshot is untouched
        $this->assertSame('225.00', $submitRes->json('data.premium_amount'));
    }

    public function test_submit_delayed_callback_returns_202_pending(): void
    {
        $this->requireDatabase();

        $payload = $this->validPayload();
        $createRes = $this->postJson('/api/policy-requests', $payload);
        $id = $createRes->json('data.id');

        $submitRes = $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'DELAYED_CALLBACK',
        ]);

        $submitRes->assertStatus(202)
            ->assertJsonPath('data.status', PolicyStatus::PendingConfirmation->value);
    }
}
