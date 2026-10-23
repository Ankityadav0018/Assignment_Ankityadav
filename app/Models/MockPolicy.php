<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model representing a mock insurer's persisted state for an issuance.
 *
 * @property int         $id
 * @property string      $idempotency_key
 * @property string|null $policy_number
 * @property string|null $provider_reference
 * @property string      $status
 * @property string      $scenario
 * @property array|null  $payload
 * @property string|null $last_event_id
 * @property int         $callback_count
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class MockPolicy extends Model
{
    protected $table = 'mock_policies';

    protected $fillable = [
        'idempotency_key',
        'policy_number',
        'provider_reference',
        'status',
        'scenario',
        'payload',
        'last_event_id',
        'callback_count',
    ];

    protected $casts = [
        'payload'        => 'array',
        'callback_count' => 'integer',
    ];
}
