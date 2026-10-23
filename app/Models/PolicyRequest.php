<?php

namespace App\Models;

use App\Enums\Destination;
use App\Enums\PolicyStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string          $id
 * @property string          $client_request_id
 * @property string          $agent_id
 * @property string          $customer_name
 * @property \Carbon\Carbon  $date_of_birth
 * @property Destination     $destination
 * @property \Carbon\Carbon  $trip_start
 * @property \Carbon\Carbon  $trip_end
 * @property int             $travel_days
 * @property string          $plan_code
 * @property float           $sum_insured
 * @property float           $premium_amount
 * @property array           $premium_breakdown   Immutable multiplier snapshot.
 * @property PolicyStatus    $status
 * @property int             $attempt_count
 * @property string|null     $provider_reference
 * @property string|null     $policy_number
 * @property array|null      $last_provider_response
 * @property string|null     $last_error           Sanitised code/message — no PII.
 * @property \Carbon\Carbon  $created_at
 * @property \Carbon\Carbon  $updated_at
 */
class PolicyRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'client_request_id',
        'agent_id',
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
        'last_provider_response',
        'last_error',
    ];

    protected $casts = [
        'date_of_birth'          => 'date',
        'trip_start'             => 'date',
        'trip_end'               => 'date',
        'destination'            => Destination::class,
        'status'                 => PolicyStatus::class,
        'premium_amount'         => 'decimal:2',
        'sum_insured'            => 'decimal:2',
        // Immutable after creation — never update this column directly.
        'premium_breakdown'      => 'array',
        'last_provider_response' => 'array',
        'attempt_count'          => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function issuanceAttempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class)->orderBy('attempt_no');
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class)->latest();
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class)->oldest('created_at');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function nextAttemptNo(): int
    {
        return $this->attempt_count + 1;
    }
}
