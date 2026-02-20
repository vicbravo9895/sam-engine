<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 - Attention Engine.
 *
 * Adds owner assignment, SLA tracking, acknowledgement status,
 * and escalation fields to enable proactive attention management.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            // Attention lifecycle state
            $table->string('attention_state', 30)->nullable()->after('reviewed_at');
            $table->string('ack_status', 20)->nullable()->after('attention_state');

            // Owner assignment
            $table->foreignId('owner_user_id')
                ->nullable()
                ->after('ack_status')
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('owner_contact_id')
                ->nullable()
                ->after('owner_user_id')
                ->constrained('contacts')
                ->nullOnDelete();

            // SLA deadlines
            $table->timestampTz('ack_due_at')->nullable()->after('owner_contact_id');
            $table->timestampTz('acked_at')->nullable()->after('ack_due_at');
            $table->timestampTz('resolve_due_at')->nullable()->after('acked_at');
            $table->timestampTz('resolved_at')->nullable()->after('resolve_due_at');

            // Escalation tracking
            $table->timestampTz('next_escalation_at')->nullable()->after('resolved_at');
            $table->unsignedSmallInteger('escalation_level')->default(0)->after('next_escalation_at');
            $table->unsignedSmallInteger('escalation_count')->default(0)->after('escalation_level');

            // Composite indexes for scheduler queries
            $table->index(
                ['attention_state', 'ack_status', 'ack_due_at'],
                'idx_attention_overdue'
            );
            $table->index(
                ['attention_state', 'next_escalation_at'],
                'idx_attention_escalation'
            );
            $table->index(
                ['company_id', 'attention_state', 'ack_due_at'],
                'idx_company_attention_sla'
            );
        });
    }

    public function down(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            $table->dropIndex('idx_attention_overdue');
            $table->dropIndex('idx_attention_escalation');
            $table->dropIndex('idx_company_attention_sla');

            $table->dropConstrainedForeignId('owner_user_id');
            $table->dropConstrainedForeignId('owner_contact_id');

            $table->dropColumn([
                'attention_state',
                'ack_status',
                'ack_due_at',
                'acked_at',
                'resolve_due_at',
                'resolved_at',
                'next_escalation_at',
                'escalation_level',
                'escalation_count',
            ]);
        });
    }
};
