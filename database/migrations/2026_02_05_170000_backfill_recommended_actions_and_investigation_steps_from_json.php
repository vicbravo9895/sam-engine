<?php

use Illuminate\Database\Migrations\Migration;

/**
 * T3 — Fuente de verdad única (legacy backfill).
 *
 * Originally populated event_recommended_actions and event_investigation_steps
 * from the legacy SamsaraEvent model. Now a no-op since the model was replaced
 * by Alert and the data has been migrated.
 */
return new class extends Migration {
    public function up(): void
    {
        // No-op: SamsaraEvent model has been replaced by Alert.
        // Backfill was completed before the migration to the new model.
    }

    public function down(): void
    {
        // No-op
    }
};
