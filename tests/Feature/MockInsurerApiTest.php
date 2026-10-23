<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MockInsurerApiTest extends TestCase
{
    private function requireDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database connection not reachable: ' . $e->getMessage());
        }
    }

    // ── HTTP Validation Tests (No DB required) ────────────────────────────────

    public function test_issue_requires_idempotency_key(): void
    {
        $response = $this->postJson('/api/mock-insurer/issue', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_callback_validates_required_payload(): void
    {
        $response = $this->postJson('/api/mock-insurer/callback', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'provider_event_id',
                'provider_reference',
                'policy_number',
                'event_type',
            ]);
    }

    // ── Database-backed Endpoints ─────────────────────────────────────────────

    public function test_issue_success_returns_deterministic_policy_and_reference(): void
    {
        $this->requireDatabase();

        $key = 'req-api-success-' . uniqid();

        $response = $this->postJson('/api/mock-insurer/issue', [
            'idempotency_key' => $key,
            'scenario'        => 'SUCCESS',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status'  => 'ISSUED',
            ])
            ->assertJsonStructure([
                'policy_number',
                'provider_reference',
            ]);
    }

    public function test_issue_is_idempotent_via_api(): void
    {
        $this->requireDatabase();

        $key = 'req-api-idem-' . uniqid();

        $res1 = $this->postJson('/api/mock-insurer/issue', [
            'idempotency_key' => $key,
        ]);
        $res1->assertStatus(200);

        $res2 = $this->postJson('/api/mock-insurer/issue', [
            'idempotency_key' => $key,
        ]);
        $res2->assertStatus(200);

        $this->assertSame($res1->json('policy_number'), $res2->json('policy_number'));
        $this->assertSame($res1->json('provider_reference'), $res2->json('provider_reference'));
    }

    public function test_issue_temporary_failure_returns_503(): void
    {
        $this->requireDatabase();

        $key = 'req-api-fail-' . uniqid();

        $response = $this->postJson('/api/mock-insurer/issue', [
            'idempotency_key' => $key,
            'scenario'        => 'TEMPORARY_FAILURE',
        ]);

        $response->assertStatus(503)
            ->assertJson([
                'success'      => false,
                'status'       => 'FAILED',
                'is_retryable' => true,
                'error'        => 'TEMPORARY_SERVICE_UNAVAILABLE',
            ]);
    }

    public function test_issue_timeout_after_issue_returns_504_and_persists_policy(): void
    {
        $this->requireDatabase();

        $key = 'req-api-timeout-' . uniqid();

        $response = $this->postJson('/api/mock-insurer/issue', [
            'idempotency_key' => $key,
            'scenario'        => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $response->assertStatus(504)
            ->assertJson([
                'status'          => 'TIMEOUT',
                'idempotency_key' => $key,
                'is_retryable'    => true,
            ]);

        // Status lookup confirms the policy was actually issued
        $lookup = $this->getJson("/api/mock-insurer/status/{$key}");
        $lookup->assertStatus(200)
            ->assertJson([
                'status' => 'ISSUED',
            ]);
    }

    public function test_delayed_callback_flow(): void
    {
        $this->requireDatabase();

        $key = 'req-api-delayed-' . uniqid();

        // 1. Issue returns 202 PENDING
        $response = $this->postJson('/api/mock-insurer/issue', [
            'idempotency_key' => $key,
            'scenario'        => 'DELAYED_CALLBACK',
        ]);
        $response->assertStatus(202)
            ->assertJson([
                'status' => 'PENDING',
            ]);

        $ref = $response->json('provider_reference');
        $this->assertNotNull($ref);

        // 2. Trigger callback sends provider event payload
        $cbRes = $this->postJson('/api/mock-insurer/callback', [
            'provider_event_id'  => 'EVT-' . uniqid(),
            'provider_reference' => $ref,
            'policy_number'      => 'POL-' . uniqid(),
            'event_type'         => 'POLICY_ISSUED',
        ]);
        $cbRes->assertStatus(200)
            ->assertJson([
                'status' => 'processed',
            ]);
    }

    public function test_status_lookup_nonexistent_returns_404(): void
    {
        $this->requireDatabase();

        $response = $this->getJson('/api/mock-insurer/status/nonexistent-key');
        $response->assertStatus(404);
    }
}
