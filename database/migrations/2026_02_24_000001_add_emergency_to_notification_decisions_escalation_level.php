<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Añade el valor 'emergency' al check constraint de escalation_level en notification_decisions.
 *
 * Resuelve Sentry: SQLSTATE[23514] notification_decisions_escalation_level_check
 * cuando el Attention Engine o el job persisten escalation_level = 'emergency'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notification_decisions DROP CONSTRAINT IF EXISTS notification_decisions_escalation_level_check');
        DB::statement("ALTER TABLE notification_decisions ADD CONSTRAINT notification_decisions_escalation_level_check CHECK (escalation_level::text = ANY (ARRAY['critical'::text, 'high'::text, 'low'::text, 'none'::text, 'emergency'::text]))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notification_decisions DROP CONSTRAINT IF EXISTS notification_decisions_escalation_level_check');
        DB::statement("ALTER TABLE notification_decisions ADD CONSTRAINT notification_decisions_escalation_level_check CHECK (escalation_level::text = ANY (ARRAY['critical'::text, 'high'::text, 'low'::text, 'none'::text]))");
    }
};
