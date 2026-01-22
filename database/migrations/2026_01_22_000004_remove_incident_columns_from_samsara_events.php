<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes legacy incident correlation columns from samsara_events.
 * 
 * These columns linked events to the old alert_incidents system.
 * The new system uses the incidents table with safety_signals pivot.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            // Drop foreign key constraint first
            $table->dropForeign(['incident_id']);
            
            // Drop indexes
            $table->dropIndex(['incident_id']);
            $table->dropIndex(['incident_id', 'occurred_at']);
            
            // Drop columns
            $table->dropColumn(['incident_id', 'is_primary_event']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('samsara_events', function (Blueprint $table) {
            // Recreate columns
            $table->foreignId('incident_id')
                ->nullable()
                ->after('raw_ai_output')
                ->constrained('alert_incidents')
                ->nullOnDelete();
            
            $table->boolean('is_primary_event')->default(false)->after('incident_id');
            
            // Recreate indexes
            $table->index('incident_id');
            $table->index(['incident_id', 'occurred_at']);
        });
    }
};
