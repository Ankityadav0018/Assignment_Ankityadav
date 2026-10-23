<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();

            // Opaque identifier sent by the provider — used for deduplication.
            $table->string('provider_event_id')->unique('uq_provider_events_provider_event_id');

            $table->uuid('policy_request_id');
            $table->foreign('policy_request_id')
                  ->references('id')
                  ->on('policy_requests')
                  ->cascadeOnDelete();

            // Raw event payload as received from the provider.
            $table->json('payload');

            // Null until the event has been successfully processed.
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index('policy_request_id', 'idx_provider_events_policy_request');
            $table->index('processed_at', 'idx_provider_events_processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
