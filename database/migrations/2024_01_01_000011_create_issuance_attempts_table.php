<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();

            $table->uuid('policy_request_id');
            $table->foreign('policy_request_id')
                  ->references('id')
                  ->on('policy_requests')
                  ->cascadeOnDelete();

            // Sequential attempt number for this policy request (1-based).
            $table->unsignedSmallInteger('attempt_no');

            // Outcome of this specific attempt.
            $table->string('outcome'); // e.g. SUCCESS, FAILED, TIMEOUT

            // Full provider response payload for this attempt.
            $table->json('response')->nullable();

            // Sanitised error code/message only.
            // Do NOT store PII, raw stack traces, or sensitive provider data here.
            $table->string('error', 500)->nullable();

            $table->timestamps();

            $table->index('policy_request_id', 'idx_issuance_attempts_policy_request');
            $table->unique(['policy_request_id', 'attempt_no'], 'uq_issuance_attempts_attempt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
