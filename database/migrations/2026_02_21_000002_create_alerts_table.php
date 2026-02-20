<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->uuid('signal_id');
            $table->unsignedBigInteger('legacy_samsara_event_id')->nullable();

            // AI assessment
            $table->string('ai_status', 30)->default('pending');
            $table->string('severity', 20)->nullable();
            $table->string('verdict', 40)->nullable();
            $table->string('likelihood', 40)->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->text('reasoning')->nullable();
            $table->text('ai_message')->nullable();

            // Classification
            $table->string('alert_kind', 60)->nullable();
            $table->string('dedupe_key')->nullable();
            $table->string('risk_escalation', 30)->nullable();
            $table->boolean('proactive_flag')->default(false);

            // Human review
            $table->string('human_status', 30)->nullable();
            $table->unsignedBigInteger('reviewed_by_id')->nullable();
            $table->timestampTz('reviewed_at')->nullable();

            // Attention engine
            $table->string('attention_state', 30)->nullable();
            $table->string('ack_status', 20)->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->unsignedBigInteger('owner_contact_id')->nullable();
            $table->timestampTz('ack_due_at')->nullable();
            $table->timestampTz('acked_at')->nullable();
            $table->timestampTz('resolve_due_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('next_escalation_at')->nullable();
            $table->unsignedSmallInteger('escalation_level')->default(0);
            $table->unsignedSmallInteger('escalation_count')->default(0);

            $table->timestampTz('occurred_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('signal_id')->references('id')->on('signals')->cascadeOnDelete();
            $table->foreign('legacy_samsara_event_id')->references('id')->on('samsara_events')->nullOnDelete();
            $table->foreign('reviewed_by_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['company_id', 'ai_status']);
            $table->index(['company_id', 'severity']);
            $table->index(['company_id', 'attention_state']);
            $table->index(['company_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
