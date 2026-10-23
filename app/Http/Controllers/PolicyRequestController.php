<?php

namespace App\Http\Controllers;

use App\Enums\Destination;
use App\Enums\PolicyStatus;
use App\Http\Requests\StorePolicyRequestRequest;
use App\Http\Resources\PolicyRequestResource;
use App\Models\PolicyRequest;
use App\Services\PremiumCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PolicyRequestController extends Controller
{
    public function __construct(
        private readonly PremiumCalculator $calculator,
    ) {}

    // ── POST /api/policy-requests ─────────────────────────────────────────────

    /**
     * Create a new policy request.
     *
     * Idempotency strategy (optimistic): attempt the INSERT and catch the
     * UniqueConstraintViolationException thrown by the DB unique index on
     * (agent_id, client_request_id).  Never do a SELECT-then-INSERT check,
     * which has a TOCTOU race.
     *
     * @return JsonResponse  201 on first creation, 200 on duplicate.
     */
    public function store(StorePolicyRequestRequest $request): JsonResponse
    {
        $data = $request->validated();

        // ── Calculate premium before touching the DB ──────────────────────────
        $destination = Destination::from(strtoupper($data['destination']));
        $planCode    = strtoupper($data['plan_code']);

        $result = $this->calculator->calculate(
            planCode:    $planCode,
            dateOfBirth: CarbonImmutable::parse($data['date_of_birth']),
            tripStart:   CarbonImmutable::parse($data['trip_start']),
            tripEnd:     CarbonImmutable::parse($data['trip_end']),
            destination: $destination,
            sumInsured:  (int) $data['sum_insured'],
        );

        // ── Persist inside a transaction ──────────────────────────────────────
        try {
            $policyRequest = DB::transaction(function () use ($data, $result, $destination, $planCode): PolicyRequest {

                // 1. Insert with RECEIVED status.
                $pr = PolicyRequest::create([
                    'client_request_id' => $data['client_request_id'],
                    'agent_id'          => $data['agent_id'],
                    'customer_name'     => $data['customer_name'],
                    'date_of_birth'     => $data['date_of_birth'],
                    'destination'       => $destination,
                    'trip_start'        => $data['trip_start'],
                    'trip_end'          => $data['trip_end'],
                    'travel_days'       => $result->breakdown['travel_days'],
                    'plan_code'         => $planCode,
                    'sum_insured'       => $data['sum_insured'],
                    'premium_amount'    => $result->premiumAmount,
                    'premium_breakdown' => $result->breakdown,
                    'status'            => PolicyStatus::Received,
                    'attempt_count'     => 0,
                ]);

                // 2. Record RECEIVED transition.
                $pr->stateHistory()->create([
                    'from_status' => null,
                    'to_status'   => PolicyStatus::Received->value,
                    'reason'      => 'Policy request received.',
                ]);

                // 3. Transition to VALIDATED.
                $pr->status = PolicyStatus::Validated;
                $pr->save();

                // 4. Record VALIDATED transition.
                $pr->stateHistory()->create([
                    'from_status' => PolicyStatus::Received->value,
                    'to_status'   => PolicyStatus::Validated->value,
                    'reason'      => 'Input validated; premium calculated and snapshot stored.',
                ]);

                $pr->load('stateHistory');

                return $pr;
            });

            return PolicyRequestResource::make($policyRequest)
                ->response()
                ->setStatusCode(201);

        } catch (UniqueConstraintViolationException) {
            // Duplicate (agent_id, client_request_id) — return the existing row.
            // The premium has already been calculated above; we discard it and
            // return whatever was committed on the original request.
            $existing = PolicyRequest::where('agent_id', $data['agent_id'])
                ->where('client_request_id', $data['client_request_id'])
                ->with('stateHistory')
                ->firstOrFail();

            return PolicyRequestResource::make($existing)
                ->response()
                ->setStatusCode(200);
        }
    }

    // ── GET /api/policy-requests/{policyRequest} ──────────────────────────────

    /**
     * Retrieve a single policy request with its full audit trail.
     *
     * Route model binding resolves the UUID automatically via HasUuids.
     */
    public function show(PolicyRequest $policyRequest): PolicyRequestResource
    {
        $policyRequest->load('stateHistory');

        return PolicyRequestResource::make($policyRequest);
    }

    // ── GET /api/policy-requests ──────────────────────────────────────────────

    /**
     * List policy requests with optional status filter and cursor-based
     * pagination (20 per page by default).
     *
     * Query params:
     *   status (string)  - Filter by exact status value, e.g. ?status=VALIDATED
     *   per_page (int)   - Results per page (max 100, default 20)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PolicyRequest::query()->latest('created_at');

        // ── Status filter ─────────────────────────────────────────────────────
        if ($request->filled('status')) {
            $status = PolicyStatus::tryFrom(strtoupper($request->string('status')->toString()));
            if ($status !== null) {
                $query->where('status', $status->value);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // ── Pagination ────────────────────────────────────────────────────────
        $perPage = max(1, min((int) $request->input('per_page', 20), 100));

        // State history is intentionally NOT loaded here to avoid N+1.
        // Use the GET /api/policy-requests/{id} endpoint for the audit trail.
        return PolicyRequestResource::collection($query->paginate($perPage));
    }
}
