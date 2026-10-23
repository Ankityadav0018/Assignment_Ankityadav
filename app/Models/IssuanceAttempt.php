<?php

namespace App\Models;

use App\Enums\IssuanceOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per provider call made for a PolicyRequest.
 * Records are append-only; never update or delete them.
 *
 * @property int              $id
 * @property string           $policy_request_id
 * @property int              $attempt_no
 * @property IssuanceOutcome  $outcome
 * @property array|null       $response
 * @property string|null      $error  Sanitised error code/message — no PII.
 * @property \Carbon\Carbon   $created_at
 * @property \Carbon\Carbon   $updated_at
 */
class IssuanceAttempt extends Model
{
    protected $fillable = [
        'policy_request_id',
        'attempt_no',
        'outcome',
        'response',
        'error',
    ];

    protected $casts = [
        'outcome'    => IssuanceOutcome::class,
        'response'   => 'array',
        'attempt_no' => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
