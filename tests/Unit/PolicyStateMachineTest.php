<?php

namespace Tests\Unit;

use App\Enums\PolicyStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Services\PolicyStateMachine;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PolicyStateMachineTest extends TestCase
{
    private PolicyStateMachine $stateMachine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateMachine = new PolicyStateMachine();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── 1. Allowed Transitions Verification ───────────────────────────────────

    public static function allowedTransitionsProvider(): array
    {
        return [
            'RECEIVED -> VALIDATED'                              => [PolicyStatus::Received, PolicyStatus::Validated],
            'VALIDATED -> SUBMITTED'                             => [PolicyStatus::Validated, PolicyStatus::Submitted],
            'SUBMITTED -> ISSUED'                                => [PolicyStatus::Submitted, PolicyStatus::Issued],
            'SUBMITTED -> PENDING_CONFIRMATION'                  => [PolicyStatus::Submitted, PolicyStatus::PendingConfirmation],
            'SUBMITTED -> FAILED'                                => [PolicyStatus::Submitted, PolicyStatus::Failed],
            'SUBMITTED -> NEEDS_RECONCILIATION'                  => [PolicyStatus::Submitted, PolicyStatus::NeedsReconciliation],
            'SUBMITTED -> VALIDATED (retryable failure)'         => [PolicyStatus::Submitted, PolicyStatus::Validated],
            'PENDING_CONFIRMATION -> ISSUED'                     => [PolicyStatus::PendingConfirmation, PolicyStatus::Issued],
            'PENDING_CONFIRMATION -> FAILED'                     => [PolicyStatus::PendingConfirmation, PolicyStatus::Failed],
            'PENDING_CONFIRMATION -> NEEDS_RECONCILIATION'       => [PolicyStatus::PendingConfirmation, PolicyStatus::NeedsReconciliation],
            'NEEDS_RECONCILIATION -> ISSUED'                     => [PolicyStatus::NeedsReconciliation, PolicyStatus::Issued],
            'NEEDS_RECONCILIATION -> FAILED'                     => [PolicyStatus::NeedsReconciliation, PolicyStatus::Failed],
            'NEEDS_RECONCILIATION -> VALIDATED'                  => [PolicyStatus::NeedsReconciliation, PolicyStatus::Validated],
        ];
    }

    #[DataProvider('allowedTransitionsProvider')]
    public function test_allowed_transitions_return_true(PolicyStatus $from, PolicyStatus $to): void
    {
        $this->assertTrue(
            $this->stateMachine->canTransition($from, $to),
            "Expected transition from {$from->value} to {$to->value} to be allowed."
        );
    }

    // ── 2. Terminal States Verification ───────────────────────────────────────

    public function test_issued_and_failed_are_terminal(): void
    {
        $this->assertTrue(PolicyStatus::Issued->isTerminal());
        $this->assertTrue(PolicyStatus::Failed->isTerminal());

        $this->assertFalse(PolicyStatus::Received->isTerminal());
        $this->assertFalse(PolicyStatus::Validated->isTerminal());
        $this->assertFalse(PolicyStatus::Submitted->isTerminal());
        $this->assertFalse(PolicyStatus::PendingConfirmation->isTerminal());
        $this->assertFalse(PolicyStatus::NeedsReconciliation->isTerminal());
    }

    // ── 3. Every Illegal Transition & Regression Provider ─────────────────────

    /**
     * Enumerates every illegal transition (37 invalid combinations across the 7 states).
     * Includes all regressions (e.g., ISSUED->SUBMITTED, NEEDS_RECONCILIATION->SUBMITTED,
     * PENDING_CONFIRMATION->SUBMITTED, VALIDATED->RECEIVED, etc.).
     */
    public static function illegalTransitionsProvider(): array
    {
        $cases = [];
        $allStatuses = PolicyStatus::cases();

        foreach ($allStatuses as $from) {
            foreach ($allStatuses as $to) {
                $allowed = PolicyStateMachine::ALLOWED_TRANSITIONS[$from->value] ?? [];
                if (! in_array($to->value, $allowed, true)) {
                    $cases["{$from->value} -> {$to->value}"] = [$from, $to];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('illegalTransitionsProvider')]
    public function test_can_transition_returns_false_for_illegal_move(PolicyStatus $from, PolicyStatus $to): void
    {
        $this->assertFalse(
            $this->stateMachine->canTransition($from, $to),
            "Expected transition from {$from->value} to {$to->value} to be forbidden."
        );
    }

    #[DataProvider('illegalTransitionsProvider')]
    public function test_transition_throws_invalid_transition_exception_for_illegal_move(
        PolicyStatus $from,
        PolicyStatus $to
    ): void {
        $policy = new PolicyRequest();
        $policy->status = $from;

        try {
            $this->stateMachine->transition($policy, $to);
            $this->fail("Expected InvalidTransitionException for {$from->value} -> {$to->value}");
        } catch (InvalidTransitionException $e) {
            $this->assertSame($from, $e->from);
            $this->assertSame($to, $e->to);
            $this->assertStringContainsString(
                "Cannot transition policy request from {$from->value} to {$to->value}.",
                $e->getMessage()
            );
        }
    }

    // ── 4. Explicit Regression Assertions ─────────────────────────────────────

    public function test_cannot_regress_from_issued_to_submitted(): void
    {
        $policy = new PolicyRequest();
        $policy->status = PolicyStatus::Issued;

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Cannot transition policy request from ISSUED to SUBMITTED.');

        $this->stateMachine->transition($policy, PolicyStatus::Submitted);
    }

    public function test_cannot_regress_from_needs_reconciliation_to_submitted(): void
    {
        // Prompt explicitly states: NEEDS_RECONCILIATION->ISSUED|FAILED|SUBMITTED-not-allowed
        $policy = new PolicyRequest();
        $policy->status = PolicyStatus::NeedsReconciliation;

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Cannot transition policy request from NEEDS_RECONCILIATION to SUBMITTED.');

        $this->stateMachine->transition($policy, PolicyStatus::Submitted);
    }

    public function test_cannot_regress_from_pending_confirmation_to_submitted(): void
    {
        $policy = new PolicyRequest();
        $policy->status = PolicyStatus::PendingConfirmation;

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Cannot transition policy request from PENDING_CONFIRMATION to SUBMITTED.');

        $this->stateMachine->transition($policy, PolicyStatus::Submitted);
    }

    public function test_cannot_regress_from_validated_to_received(): void
    {
        $policy = new PolicyRequest();
        $policy->status = PolicyStatus::Validated;

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Cannot transition policy request from VALIDATED to RECEIVED.');

        $this->stateMachine->transition($policy, PolicyStatus::Received);
    }

    public function test_cannot_regress_from_submitted_to_received(): void
    {
        $policy = new PolicyRequest();
        $policy->status = PolicyStatus::Submitted;

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Cannot transition policy request from SUBMITTED to RECEIVED.');

        $this->stateMachine->transition($policy, PolicyStatus::Received);
    }

    public function test_cannot_regress_from_failed_to_any_state(): void
    {
        foreach (PolicyStatus::cases() as $target) {
            $policy = new PolicyRequest();
            $policy->status = PolicyStatus::Failed;

            try {
                $this->stateMachine->transition($policy, $target);
                $this->fail("Failed should not transition to {$target->value}");
            } catch (InvalidTransitionException $e) {
                $this->assertSame(PolicyStatus::Failed, $e->from);
                $this->assertSame($target, $e->to);
            }
        }
    }

    public function test_cannot_regress_from_issued_to_any_state(): void
    {
        foreach (PolicyStatus::cases() as $target) {
            $policy = new PolicyRequest();
            $policy->status = PolicyStatus::Issued;

            try {
                $this->stateMachine->transition($policy, $target);
                $this->fail("Issued should not transition to {$target->value}");
            } catch (InvalidTransitionException $e) {
                $this->assertSame(PolicyStatus::Issued, $e->from);
                $this->assertSame($target, $e->to);
            }
        }
    }

    public function test_issued_can_never_go_back_to_any_earlier_state(): void
    {
        $earlierStates = [
            PolicyStatus::Received,
            PolicyStatus::Validated,
            PolicyStatus::Submitted,
            PolicyStatus::PendingConfirmation,
            PolicyStatus::NeedsReconciliation,
        ];

        foreach ($earlierStates as $earlierState) {
            $this->assertFalse(
                $this->stateMachine->canTransition(PolicyStatus::Issued, $earlierState),
                "ISSUED should never be permitted to transition back to {$earlierState->value}"
            );

            $policy = new PolicyRequest();
            $policy->status = PolicyStatus::Issued;

            try {
                $this->stateMachine->transition($policy, $earlierState);
                $this->fail("Expected InvalidTransitionException transitioning ISSUED -> {$earlierState->value}");
            } catch (InvalidTransitionException $e) {
                $this->assertSame(PolicyStatus::Issued, $e->from);
                $this->assertSame($earlierState, $e->to);
            }
        }
    }

    // ── 5. Successful Transition Executes Row Update & History ───────────────

    public function test_successful_transition_updates_status_and_appends_history(): void
    {
        $historyRelation = Mockery::mock(HasMany::class);
        $historyRelation->shouldReceive('create')
            ->once()
            ->with([
                'from_status' => PolicyStatus::Received->value,
                'to_status'   => PolicyStatus::Validated->value,
                'reason'      => 'Premium calculated and verified.',
            ])
            ->andReturn(new PolicyStateHistory());

        $policy = Mockery::mock(PolicyRequest::class)->makePartial();
        $policy->status = PolicyStatus::Received;

        $policy->shouldReceive('save')
            ->once()
            ->andReturn(true);

        $policy->shouldReceive('stateHistory')
            ->once()
            ->andReturn($historyRelation);

        $result = $this->stateMachine->transition(
            $policy,
            PolicyStatus::Validated,
            'Premium calculated and verified.'
        );

        $this->assertSame($policy, $result);
        $this->assertSame(PolicyStatus::Validated, $policy->status);
    }

    public function test_accepts_string_status_in_transition(): void
    {
        $historyRelation = Mockery::mock(HasMany::class);
        $historyRelation->shouldReceive('create')
            ->once()
            ->with([
                'from_status' => 'RECEIVED',
                'to_status'   => 'VALIDATED',
                'reason'      => null,
            ])
            ->andReturn(new PolicyStateHistory());

        $policy = Mockery::mock(PolicyRequest::class)->makePartial();
        $policy->status = PolicyStatus::Received;
        $policy->shouldReceive('save')->once()->andReturn(true);
        $policy->shouldReceive('stateHistory')->once()->andReturn($historyRelation);

        $this->stateMachine->transition($policy, 'VALIDATED');
        $this->assertSame(PolicyStatus::Validated, $policy->status);
    }
}
