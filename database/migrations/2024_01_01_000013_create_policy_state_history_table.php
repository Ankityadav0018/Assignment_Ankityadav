<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_state_history', function (Blueprint $table) {
            $table->id();

            $table->uuid('policy_request_id');
            $table->foreign('policy_request_id')
                  ->references('id')
                  ->on('policy_requests')
                  ->cascadeOnDelete();

            // Null on the very first recorded state (i.e. record creation).
            $table->string('from_status')->nullable();
            $table->string('to_status');

            // Human-readable reason for the transition.
            // Must NOT contain PII — use codes or templated messages only.
            $table->string('reason', 500)->nullable();

            // Append-only: no updated_at column.
            $table->timestamp('created_at')->useCurrent();

            $table->index('policy_request_id', 'idx_policy_state_history_policy_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_state_history');
    }
};
