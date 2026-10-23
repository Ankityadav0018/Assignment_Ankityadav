<?php

namespace App\Contracts;

use App\Services\Insurer\InsurerResponse;

interface InsurerClient
{
    /**
     * Submit an issuance request for a policy to the insurer.
     *
     * Must be idempotent per $idempotencyKey.
     *
     * @param  string       $idempotencyKey  Unique client/policy request ID.
     * @param  array        $payload         Policy details (customer, dates, plan, premium).
     * @param  string|null  $scenario        Dev-only simulation scenario override.
     * @return InsurerResponse
     */
    public function issue(
        string $idempotencyKey,
        array $payload = [],
        ?string $scenario = null,
    ): InsurerResponse;

    /**
     * Lookup the current issuance status for a given idempotency key.
     *
     * @param  string $idempotencyKey
     * @return InsurerResponse|null
     */
    public function lookup(string $idempotencyKey): ?InsurerResponse;

    /**
     * Trigger callback/webhook event(s) for a policy.
     *
     * @param  string      $idempotencyKey
     * @param  string|null $scenario
     * @param  string|null $targetUrl
     * @return array Emitted callback event payloads.
     */
    public function triggerCallback(
        string $idempotencyKey,
        ?string $scenario = null,
        ?string $targetUrl = null,
    ): array;
}
