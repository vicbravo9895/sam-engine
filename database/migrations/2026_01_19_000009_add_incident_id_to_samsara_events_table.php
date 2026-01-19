<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds incident_id and is_primary_event columns to samsara_events.
 * 
 * Links events to their parent incident for quick lookups.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            // Foreign key to alert_incidents (nullable - not all events are part of an incident)
            $table->foreignId('incident_id')
                ->nullable()
                ->after('raw_ai_output')
                ->constrained('alert_incidents')
                ->nullOnDelete();
            
            // Flag to indicate if this is the primary event of an incident
            $table->boolean('is_primary_event')->default(false)->after('incident_id');
            
            // Indexes
            $table->index('incident_id');
            $table->index(['incident_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            $table->dropForeign(['incident_id']);
            $table->dropIndex(['incident_id']);
            $table->dropIndex(['incident_id', 'occurred_at']);
            $table->dropColumn(['incident_id', 'is_primary_event']);
        });
    }
};
