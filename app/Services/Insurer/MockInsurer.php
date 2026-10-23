<?php

namespace App\Services\Insurer;

use App\Contracts\InsurerClient;
use App\Enums\MockInsurerScenario;
use App\Exceptions\InsurerTemporaryFailureException;
use App\Exceptions\InsurerTimeoutException;
use App\Models\MockPolicy;
use Illuminate\Support\Facades\Http;

class MockInsurer implements InsurerClient
{
    /**
     * Deterministically derive a policy number from the idempotency key.
     */
    public static function generatePolicyNumber(string $idempotencyKey): string
    {
        return 'POL-' . strtoupper(substr(hash('sha256', 'policyflow:mock_pol:' . $idempotencyKey), 0, 10));
    }

    /**
     * Deterministically derive a provider reference from the idempotency key.
     */
    public static function generateProviderReference(string $idempotencyKey): string
    {
        return 'REF-' . strtoupper(substr(hash('sha256', 'policyflow:mock_ref:' . $idempotencyKey), 0, 10));
    }

    /**
     * Deterministically derive an event ID from the idempotency key.
     */
    public static function generateEventId(string $idempotencyKey, int $sequence = 1): string
    {
        return 'EVT-' . strtoupper(substr(hash('sha256', 'policyflow:mock_evt:' . $idempotencyKey . ':' . $sequence), 0, 12));
    }

    /**
     * Resolve the simulation scenario.
     * Scenario overrides and headers are honored ONLY in 'local' and 'testing'.
     * In all other environments (e.g. production), they are ignored and SUCCESS is returned.
     */
    public function resolveScenario(?string $override = null): MockInsurerScenario
    {
        if (! app()->environment(['local', 'testing'])) {
            return MockInsurerScenario::Success;
        }

        if ($override !== null && $override !== '') {
            return MockInsurerScenario::tryFrom(strtoupper(trim($override))) ?? MockInsurerScenario::Success;
        }

        if (function_exists('request') && request()?->hasHeader('X-Mock-Scenario')) {
            $header = request()->header('X-Mock-Scenario');
            if (is_string($header) && $header !== '') {
                return MockInsurerScenario::tryFrom(strtoupper(trim($header))) ?? MockInsurerScenario::Success;
            }
        }

        $configScenario = config('services.mock_insurer.scenario', 'SUCCESS');
        if (is_string($configScenario) && $configScenario !== '') {
            return MockInsurerScenario::tryFrom(strtoupper(trim($configScenario))) ?? MockInsurerScenario::Success;
        }

        return MockInsurerScenario::Success;
    }

    /**
     * Submit an issuance request. Idempotent per $idempotencyKey.
     */
    public function issue(
        string $idempotencyKey,
        array $payload = [],
        ?string $scenario = null,
    ): InsurerResponse {
        // ── Idempotency Check: return existing policy if already issued/created ──
        $existing = MockPolicy::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $this->formatResponseForPolicy($existing);
        }

        $resolvedScenario = $this->resolveScenario($scenario);

        // ── Scenario: TEMPORARY_FAILURE ───────────────────────────────────────
        if ($resolvedScenario === MockInsurerScenario::TemporaryFailure) {
            return InsurerResponse::temporaryFailure(
                error: 'TEMPORARY_SERVICE_UNAVAILABLE',
                rawResponse: [
                    'status'       => 'FAILED',
                    'error'        => 'TEMPORARY_SERVICE_UNAVAILABLE',
                    'is_retryable' => true,
                    'scenario'     => $resolvedScenario->value,
                ],
            );
        }

        $policyNumber      = self::generatePolicyNumber($idempotencyKey);
        $providerReference = self::generateProviderReference($idempotencyKey);

        // ── Scenario: TIMEOUT_AFTER_ISSUE ─────────────────────────────────────
        // "creates the policy in mock_policies and then throws a timeout exception to the caller"
        if ($resolvedScenario === MockInsurerScenario::TimeoutAfterIssue) {
            MockPolicy::create([
                'idempotency_key'    => $idempotencyKey,
                'policy_number'      => $policyNumber,
                'provider_reference' => $providerReference,
                'status'             => 'ISSUED',
                'scenario'           => $resolvedScenario->value,
                'payload'            => $payload,
                'callback_count'     => 0,
            ]);

            throw new InsurerTimeoutException(
                message: 'Provider connection timed out after issuance.',
                idempotencyKey: $idempotencyKey,
            );
        }

        // ── Scenario: TIMEOUT_WITHOUT_ISSUE / TIMEOUT_BEFORE_ISSUE ────────────
        // Times out without creating any mock_policies row (status lookup returns 404 / null)
        if ($resolvedScenario === MockInsurerScenario::TimeoutWithoutIssue || $resolvedScenario === MockInsurerScenario::TimeoutBeforeIssue) {
            throw new InsurerTimeoutException(
                message: 'Provider connection timed out before issuance.',
                idempotencyKey: $idempotencyKey,
            );
        }

        // ── Scenario: DELAYED_CALLBACK ────────────────────────────────────────
        // "returns pending and later emits a success event"
        if ($resolvedScenario === MockInsurerScenario::DelayedCallback) {
            $policy = MockPolicy::create([
                'idempotency_key'    => $idempotencyKey,
                'policy_number'      => $policyNumber,
                'provider_reference' => $providerReference,
                'status'             => 'PENDING',
                'scenario'           => $resolvedScenario->value,
                'payload'            => $payload,
                'callback_count'     => 0,
            ]);

            return InsurerResponse::pending(
                providerReference: $providerReference,
                policyNumber: null,
                rawResponse: [
                    'status'             => 'PENDING',
                    'provider_reference' => $providerReference,
                    'scenario'           => $resolvedScenario->value,
                ],
            );
        }

        // ── Scenarios: SUCCESS and DUPLICATE_CALLBACK ─────────────────────────
        $policy = MockPolicy::create([
            'idempotency_key'    => $idempotencyKey,
            'policy_number'      => $policyNumber,
            'provider_reference' => $providerReference,
            'status'             => 'ISSUED',
            'scenario'           => $resolvedScenario->value,
            'payload'            => $payload,
            'callback_count'     => 0,
        ]);

        return InsurerResponse::success(
            policyNumber: $policyNumber,
            providerReference: $providerReference,
            rawResponse: [
                'status'             => 'ISSUED',
                'policy_number'      => $policyNumber,
                'provider_reference' => $providerReference,
                'scenario'           => $resolvedScenario->value,
            ],
        );
    }

    /**
     * Status lookup by idempotency key.
     */
    public function lookup(string $idempotencyKey): ?InsurerResponse
    {
        $policy = MockPolicy::where('idempotency_key', $idempotencyKey)->first();

        if ($policy === null) {
            return null;
        }

        return $this->formatResponseForPolicy($policy);
    }

    /**
     * Trigger callback/webhook event(s) for a policy.
     *
     * Handles:
     *  - DELAYED_CALLBACK: transitions PENDING -> ISSUED and emits success event
     *  - DUPLICATE_CALLBACK: emits the same event 2-3 times with identical provider_event_id
     */
    public function triggerCallback(
        string $idempotencyKey,
        ?string $scenario = null,
        ?string $targetUrl = null,
    ): array {
        $policy = MockPolicy::where('idempotency_key', $idempotencyKey)->first();

        if ($policy === null) {
            // Auto-provision if callback triggered directly
            $policyNumber      = self::generatePolicyNumber($idempotencyKey);
            $providerReference = self::generateProviderReference($idempotencyKey);
            $policy = MockPolicy::create([
                'idempotency_key'    => $idempotencyKey,
                'policy_number'      => $policyNumber,
                'provider_reference' => $providerReference,
                'status'             => 'ISSUED',
                'scenario'           => $scenario ?? 'SUCCESS',
                'callback_count'     => 0,
            ]);
        }

        $resolvedScenario = $this->resolveScenario($scenario ?? $policy->scenario);

        // Update pending policy to ISSUED upon callback
        if ($policy->status === 'PENDING') {
            $policy->status = 'ISSUED';
        }

        $eventId = self::generateEventId($idempotencyKey);
        $policy->last_event_id = $eventId;

        $eventPayload = [
            'provider_event_id'  => $eventId,
            'policy_request_id'  => $idempotencyKey,
            'event_type'         => 'POLICY_ISSUED',
            'status'             => 'ISSUED',
            'policy_number'      => $policy->policy_number,
            'provider_reference' => $policy->provider_reference,
            'emitted_at'         => now()->toIso8601String(),
        ];

        // If duplicate callback scenario: emit the same event 3 times
        $times = ($resolvedScenario === MockInsurerScenario::DuplicateCallback) ? 3 : 1;
        $emittedEvents = [];

        for ($i = 0; $i < $times; $i++) {
            $emittedEvents[] = $eventPayload;
            $policy->increment('callback_count');

            if ($targetUrl !== null && $targetUrl !== '') {
                try {
                    Http::timeout(5)->post($targetUrl, $eventPayload);
                } catch (\Throwable) {
                    // Best effort delivery in mock
                }
            }
        }

        $policy->save();

        return $emittedEvents;
    }

    private function formatResponseForPolicy(MockPolicy $policy): InsurerResponse
    {
        if ($policy->status === 'PENDING') {
            return InsurerResponse::pending(
                providerReference: (string) $policy->provider_reference,
                policyNumber: $policy->policy_number,
                rawResponse: [
                    'status'             => 'PENDING',
                    'provider_reference' => $policy->provider_reference,
                    'policy_number'      => $policy->policy_number,
                ],
            );
        }

        return InsurerResponse::success(
            policyNumber: (string) $policy->policy_number,
            providerReference: (string) $policy->provider_reference,
            rawResponse: [
                'status'             => 'ISSUED',
                'policy_number'      => $policy->policy_number,
                'provider_reference' => $policy->provider_reference,
            ],
        );
    }
}
