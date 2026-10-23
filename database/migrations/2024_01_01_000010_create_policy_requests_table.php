<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            // Primary key
            $table->uuid('id')->primary();

            // Idempotency key — unique per agent
            $table->string('client_request_id');
            $table->string('agent_id');

            // Customer information (store only what is needed for issuance)
            $table->string('customer_name');
            $table->date('date_of_birth');

            // Trip details
            $table->enum('destination', ['DOMESTIC', 'INTERNATIONAL']);
            $table->date('trip_start');
            $table->date('trip_end');
            $table->unsignedSmallInteger('travel_days');

            // Plan & pricing
            $table->string('plan_code');
            $table->decimal('sum_insured', 15, 2);
            $table->decimal('premium_amount', 12, 2);

            // Immutable snapshot of multipliers at the time of request creation.
            // Never updated after the record is created.
            $table->json('premium_breakdown');

            // Workflow state
            $table->string('status');
            $table->unsignedInteger('attempt_count')->default(0);

            // Provider linkage (nullable until the provider confirms)
            $table->string('provider_reference')->nullable();
            $table->string('policy_number')->nullable();

            // Last raw provider response (used for debugging / reconciliation)
            $table->json('last_provider_response')->nullable();

            // Sanitised error code/message only — must NOT contain PII or raw
            // provider payloads.  Store a short human-readable reason string.
            $table->string('last_error', 500)->nullable();

            $table->timestamps();

            // ── Constraints ──────────────────────────────────────────────────
            // Each agent may only submit a given client_request_id once.
            $table->unique(['agent_id', 'client_request_id'], 'uq_policy_requests_agent_client');

            // Nullable unique: MySQL allows multiple NULLs in a UNIQUE index.
            $table->unique('policy_number', 'uq_policy_requests_policy_number');

            // Fast filtering by workflow state.
            $table->index('status', 'idx_policy_requests_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
