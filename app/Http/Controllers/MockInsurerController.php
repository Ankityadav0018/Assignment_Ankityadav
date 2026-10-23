<?php

namespace App\Http\Controllers;

use App\Contracts\InsurerClient;
use App\Exceptions\InsurerTimeoutException;
use App\Services\Insurer\MockInsurer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MockInsurerController extends Controller
{
    public function __construct(
        private readonly MockInsurer $mockInsurer,
    ) {}

    /**
     * POST /api/mock-insurer/issue
     *
     * Takes:
     *   idempotency_key (required, string)
     *   scenario        (optional, string: SUCCESS|TEMPORARY_FAILURE|TIMEOUT_AFTER_ISSUE|DELAYED_CALLBACK|DUPLICATE_CALLBACK)
     *   payload         (optional, array)
     */
    public function issue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:255'],
            'scenario'        => ['nullable', 'string', 'max:50'],
            'payload'         => ['nullable', 'array'],
        ]);

        $idempotencyKey = $validated['idempotency_key'];
        $scenario       = $validated['scenario'] ?? $request->header('X-Mock-Scenario');
        $payload        = $validated['payload'] ?? [];

        try {
            $response = $this->mockInsurer->issue($idempotencyKey, $payload, $scenario);

            if (! $response->success && $response->isRetryable) {
                return response()->json($response->toArray(), 503);
            }

            $statusCode = ($response->status === 'PENDING') ? 202 : 200;

            return response()->json($response->toArray(), $statusCode);

        } catch (InsurerTimeoutException $e) {
            // "TIMEOUT_AFTER_ISSUE creates the policy in mock_policies and then throws a timeout exception"
            return response()->json([
                'status'          => 'TIMEOUT',
                'error'           => $e->getMessage(),
                'idempotency_key' => $idempotencyKey,
                'is_retryable'    => true,
            ], 504);
        }
    }

    /**
     * GET /api/mock-insurer/status/{idempotency_key}
     *
     * Lookup mock insurer policy state by idempotency key.
     */
    public function status(Request $request, ?string $idempotency_key = null): JsonResponse
    {
        $key = $idempotency_key ?? $request->query('idempotency_key');

        if (! $key) {
            return response()->json([
                'error' => 'idempotency_key is required.',
            ], 422);
        }

        $response = $this->mockInsurer->lookup((string) $key);

        if ($response === null) {
            return response()->json([
                'error' => 'No policy found for the given idempotency key.',
            ], 404);
        }

        return response()->json($response->toArray(), 200);
    }

    /**
     * POST /api/mock-insurer/callback
     *
     * Emits provider callback event(s) for a policy.
     * Takes:
     *   idempotency_key (required)
     *   scenario        (optional override, e.g. DUPLICATE_CALLBACK)
     *   target_url      (optional callback destination)
     */
    public function callback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:255'],
            'scenario'        => ['nullable', 'string', 'max:50'],
            'target_url'      => ['nullable', 'string', 'url'],
        ]);

        $events = $this->mockInsurer->triggerCallback(
            idempotencyKey: $validated['idempotency_key'],
            scenario: $validated['scenario'] ?? $request->header('X-Mock-Scenario'),
            targetUrl: $validated['target_url'] ?? null,
        );

        return response()->json([
            'events_emitted' => count($events),
            'events'         => $events,
        ], 200);
    }
}
