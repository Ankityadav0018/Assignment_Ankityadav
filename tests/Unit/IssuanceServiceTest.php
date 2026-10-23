<?php

namespace Tests\Unit;

use App\Contracts\InsurerClient;
use App\Enums\Destination;
use App\Enums\IssuanceOutcome;
use App\Enums\PolicyStatus;
use App\Exceptions\InsurerTimeoutException;
use App\Models\PolicyRequest;
use App\Services\IssuanceService;
use App\Services\Insurer\InsurerResponse;
use App\Services\PolicyStateMachine;
use DomainException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class IssuanceServiceTest extends TestCase
{
    private InsurerClient $insurer;
    private PolicyStateMachine $stateMachine;
    private IssuanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->insurer = Mockery::mock(InsurerClient::class);
        $this->stateMachine = new PolicyStateMachine();
        $this->service = new IssuanceService($this->insurer, $this->stateMachine);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function requireDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database not available: ' . $e->getMessage());
        }
    }

    private function createTestPolicy(PolicyStatus $status = PolicyStatus::Validated): PolicyRequest
    {
        $policy = PolicyRequest::create([
            'client_request_id' => 'req-' . uniqid(),
            'agent_id'          => 'agent-test',
            'customer_name'     => 'John Doe',
            'date_of_birth'     => '1990-01-01',
            'destination'       => Destination::International,
            'trip_start'        => '2026-12-01',
            'trip_end'          => '2026-12-05',
            'travel_days'       => 5,
            'plan_code'         => 'PLUS',
            'sum_insured'       => 300000,
            'premium_amount'    => '225.00',
            'premium_breakdown' => [
                'plan_code'       => 'PLUS',
                'base_daily_rate' => '20',
                'travel_days'     => 5,
            ],
            'status'            => $status,
            'attempt_count'     => 0,
        ]);

        $policy->stateHistory()->create([
            'from_status' => null,
            'to_status'   => $status->value,
            'reason'      => 'Initial state for test.',
        ]);

        return $policy;
    }

    // ── 1. If status is ISSUED: return without calling provider ───────────────

    public function test_issued_policy_returns_immediately_without_calling_provider(): void
    {
        $this->requireDatabase();

        $policy = $this->createTestPolicy(PolicyStatus::Issued);
        $policy->policy_number = 'POL-EXISTING';
        $policy->provider_reference = 'REF-EXISTING';
        $policy->save();

        // Insurer must never be called
        $this->insurer->shouldNotReceive('issue');
        $this->insurer->shouldNotReceive('lookup');

        $result = $this->service->submit($policy->id);

        $this->assertSame(PolicyStatus::Issued, $result->status);
        $this->assertSame('POL-EXISTING', $result->policy_number);
    }

    // ── 2. If PENDING_CONFIRMATION or NEEDS_RECONCILIATION: lookup, not issue ──

    public function test_pending_confirmation_does_lookup_and_resolves_if_issued(): void
    {
        $this->requireDatabase();

        $policy = $this->createTestPolicy(PolicyStatus::PendingConfirmation);
        $policy->provider_reference = 'REF-PENDING';
        $policy->save();

        // Must NOT call issue, MUST call lookup
        $this->insurer->shouldNotReceive('issue');
        $this->insurer->shouldReceive('lookup')
            ->once()
            ->with($policy->id)
            ->andReturn(InsurerResponse::success('POL-RESOLVED-1', 'REF-PENDING'));

        $result = $this->service->submit($policy->id);

        $this->assertSame(PolicyStatus::Issued, $result->status);
        $this->assertSame('POL-RESOLVED-1', $result->policy_number);
    }

    public function test_needs_reconciliation_does_lookup_and_resolves_if_issued(): void
    {
        $this->requireDatabase();

        $policy = $this->createTestPolicy(PolicyStatus::NeedsReconciliation);
        $policy->save();

        $this->insurer->shouldNotReceive('issue');
        $this->insurer->shouldReceive('lookup')
            ->once()
            ->with($policy->id)
            ->andReturn(InsurerResponse::success('POL-RESOLVED-2', 'REF-RECON'));

        $result = $this->service->submit($policy->id);

        $this->assertSame(PolicyStatus::Issued, $result->status);
        $this->assertSame('POL-RESOLVED-2', $result->policy_number);
    }

    // ── 3. Disallowed initial status ──────────────────────────────────────────

    public function test_submit_rejects_received_status(): void
    {
        $this->requireDatabase();

        $policy = $this->createTestPolicy(PolicyStatus::Received);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot submit policy request with status RECEIVED');

        $this->service->submit($policy->id);
    }

    // ── 4. Outcomes from VALIDATED ────────────────────────────────────────────

    public function test_outcome_success_issues_policy_and_records_attempt(): void
    {
        $this->requireDatabase();

        $policy = $this->createTestPolicy(PolicyStatus::Validated);

        $this->insurer->shouldReceive('issue')
            ->once()
            ->withArgs(function ($idempotencyKey) use ($policy) {
                return $idempotencyKey === $policy->id;
            })
            ->andReturn(InsurerResponse::success('POL-NEW-100', 'REF-NEW-100'));

        $result = $this->service->submit($policy->id);

        $this->assertSame(PolicyStatus::Issued, $result->status);
        $this->assertSame('POL-NEW-100', $result->policy_number);
        $this->assertSame('REF-NEW-100', $result->provider_reference);
        $this->assertSame(1, $result->attempt_count);

        // Verify attempt row
        $attempt = $result->issuanceAttempts->first();
        $this->assertNotNull($attempt);
        $this->assertSame(1, $attempt->attempt_no);
        $this->assertSame(IssuanceOutcome::Success, $attempt->outcome);

        // Verify state history progression: VALIDATED -> SUBMITTED -> ISSUED
        $history = $result->stateHistory;
        $statuses = $history->pluck('to_status')->all();
        $this->assertContains(PolicyStatus::Submitted->value, $statuses);
        $this->assertContains(PolicyStatus::Issued->value, $statuses);
    }

    public function test_outcome_pending_moves_to_pending_confirmation(): void
    {
        $this->requireDatabase();

        $policy = $this->createTestPolicy(PolicyStatus::Validated);

        $this->insurer->shouldReceive('issue')
            ->once()
            ->andReturn(InsurerResponse::pending('REF-ASYNC-1'));

        $result = $this->service->submit($policy->id);

        $this->assertSame(PolicyStatus::PendingConfirmation, $result->status);
        $this->assertSame('REF-ASYNC-1', $result->provider_reference);
        $this->assertSame(1, $result->attempt_count);

        $attempt = $result->issuanceAttempts->first();
        $this->assertSame(IssuanceOutcome::Pending, $attempt->outcome);
    }

    public function test_outcome_retriable_failure_returns_to_validated_with_snapshot_untouched(): void
    {
        $this->requireDatabase();

        $policy = $this->createTestPolicy(PolicyStatus::Validated);
        $originalBreakdown = $policy->premium_breakdown;
        $originalAmount    = $policy->premium_amount;

        $this->insurer->shouldReceive('issue')
            ->once()
            ->andReturn(InsurerResponse::temporaryFailure('SERVICE_BUSY'));

        $result = $this->service->submit($policy->id);

        // Returned to VALIDATED
        $this->assertSame(PolicyStatus::Validated, $result->status);
        $this->assertSame('SERVICE_BUSY', $result->last_error);

        // Premium untouched
        $this->assertSame($originalAmount, $result->premium_amount);
        $this->assertSame($originalBreakdown, $result->premium_breakdown);

        $attempt = $result->issuanceAttempts->first();
        $this->assertSame(IssuanceOutcome::RetryableFailure, $attempt->outcome);
        $this->assertSame('SERVICE_BUSY', $attempt->error);
    }

    public function test_outcome_timeout_moves_to_needs_reconciliation_and_resolves_if_issued(): void
    {
        $this->requireDatabase();

        $policy = $this->createTestPolicy(PolicyStatus::Validated);

        // Issue throws timeout
        $this->insurer->shouldReceive('issue')
            ->once()
            ->andThrow(new InsurerTimeoutException('Gateway timeout', $policy->id));

        // Immediate lookup finds the policy
        $this->insurer->shouldReceive('lookup')
            ->once()
            ->with($policy->id)
            ->andReturn(InsurerResponse::success('POL-RECOVERED', 'REF-RECOVERED'));

        $result = $this->service->submit($policy->id);

        // Resolved to ISSUED via the immediate lookup
        $this->assertSame(PolicyStatus::Issued, $result->status);
        $this->assertSame('POL-RECOVERED', $result->policy_number);
    }

    public function test_timeout_with_no_policy_at_provider_reconciles_to_validated_for_retry(): void
    {
        $this->requireDatabase();

        $policy = $this->createTestPolicy(PolicyStatus::Validated);

        // 1. Issue throws timeout
        $this->insurer->shouldReceive('issue')
            ->once()
            ->andThrow(new InsurerTimeoutException('Gateway timeout before receipt', $policy->id));

        // 2. Lookup returns null (provider never received/issued it -> 404)
        $this->insurer->shouldReceive('lookup')
            ->once()
            ->with($policy->id)
            ->andReturn(null);

        $result = $this->service->submit($policy->id);

        // Status moved to VALIDATED (not stuck in NEEDS_RECONCILIATION)
        $this->assertSame(PolicyStatus::Validated, $result->status);
        $this->assertSame(1, $result->attempt_count);

        // 3. Retry succeeds
        $this->insurer->shouldReceive('issue')
            ->once()
            ->andReturn(InsurerResponse::success('POL-RETRY-SUCCESS', 'REF-RETRY-SUCCESS'));

        $retryResult = $this->service->submit($policy->id);
        $this->assertSame(PolicyStatus::Issued, $retryResult->status);
        $this->assertSame('POL-RETRY-SUCCESS', $retryResult->policy_number);
        $this->assertSame(2, $retryResult->attempt_count);
    }

    public function test_repeated_submit_calls_never_call_provider_issue_again(): void
    {
        $this->requireDatabase();

        $policy = $this->createTestPolicy(PolicyStatus::Validated);

        // First call: provider issue succeeds
        $this->insurer->shouldReceive('issue')
            ->once()
            ->andReturn(InsurerResponse::success('POL-REPEAT-TEST', 'REF-REPEAT-TEST'));

        $res1 = $this->service->submit($policy->id);
        $this->assertSame(PolicyStatus::Issued, $res1->status);

        // Second call: insurer must NOT be called again
        $this->insurer->shouldNotReceive('issue');
        $this->insurer->shouldNotReceive('lookup');

        $res2 = $this->service->submit($policy->id);
        $this->assertSame(PolicyStatus::Issued, $res2->status);
        $this->assertSame('POL-REPEAT-TEST', $res2->policy_number);
    }
}
