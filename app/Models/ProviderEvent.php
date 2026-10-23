<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inbound webhook / callback events from the provider.
 * Deduplicated by provider_event_id before processing.
 *
 * @property int             $id
 * @property string          $provider_event_id
 * @property string          $policy_request_id
 * @property array           $payload
 * @property \Carbon\Carbon|null $processed_at  Null until the event is handled.
 * @property \Carbon\Carbon  $created_at
 * @property \Carbon\Carbon  $updated_at
 */
class ProviderEvent extends Model
{
    protected $fillable = [
        'provider_event_id',
        'policy_request_id',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'processed_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    public function markProcessed(): void
    {
        $this->update(['processed_at' => now()]);
    }
}
