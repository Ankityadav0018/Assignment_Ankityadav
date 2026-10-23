<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log of every PolicyRequest status transition.
 *
 * This table has no updated_at column; rows are never modified after insert.
 *
 * PRIVACY RULE: `reason` must contain only sanitised codes or templated
 * messages.  Do NOT record customer names, DOBs, or any other PII here.
 *
 * @property int             $id
 * @property string          $policy_request_id
 * @property string|null     $from_status  Null for the initial state recording.
 * @property string          $to_status
 * @property string|null     $reason       Sanitised transition reason — no PII.
 * @property \Carbon\Carbon  $created_at
 */
class PolicyStateHistory extends Model
{
    // This table is append-only; there is no updated_at column.
    const UPDATED_AT = null;

    protected $fillable = [
        'policy_request_id',
        'from_status',
        'to_status',
        'reason',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
