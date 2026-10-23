<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Consistent JSON representation of a PolicyRequest.
 *
 * Audit trail (state_history) is only included when the relationship has been
 * eagerly loaded — use ->load('stateHistory') before returning from the
 * show endpoint. The list endpoint omits it to avoid N+1 queries.
 *
 * PRIVACY: customer_name and date_of_birth are included. Ensure routes are
 * protected by appropriate authentication middleware before going to production.
 */
class PolicyRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'agent_id'           => $this->agent_id,
            'client_request_id'  => $this->client_request_id,

            // ── Customer ──────────────────────────────────────────────────────
            'customer_name'      => $this->customer_name,
            'date_of_birth'      => $this->date_of_birth->toDateString(),

            // ── Trip ──────────────────────────────────────────────────────────
            'destination'        => $this->destination->value,
            'trip_start'         => $this->trip_start->toDateString(),
            'trip_end'           => $this->trip_end->toDateString(),
            'travel_days'        => $this->travel_days,

            // ── Plan & pricing ────────────────────────────────────────────────
            'plan_code'          => $this->plan_code,
            'sum_insured'        => (int) $this->sum_insured,
            'premium_amount'     => $this->premium_amount,
            'premium_breakdown'  => $this->premium_breakdown,

            // ── Workflow ──────────────────────────────────────────────────────
            'status'             => $this->status->value,
            'attempt_count'      => $this->attempt_count,

            // ── Provider references ───────────────────────────────────────────
            'provider_reference' => $this->provider_reference,
            'policy_number'      => $this->policy_number,

            // ── Audit trail (only when relationship is loaded) ─────────────────
            'state_history'      => $this->whenLoaded('stateHistory', function () {
                return $this->stateHistory->map(fn ($entry) => [
                    'from_status' => $entry->from_status,
                    'to_status'   => $entry->to_status,
                    'reason'      => $entry->reason,
                    'at'          => $entry->created_at->toIso8601String(),
                ]);
            }),

            // ── Timestamps ────────────────────────────────────────────────────
            'created_at'         => $this->created_at->toIso8601String(),
            'updated_at'         => $this->updated_at->toIso8601String(),
        ];
    }
}
