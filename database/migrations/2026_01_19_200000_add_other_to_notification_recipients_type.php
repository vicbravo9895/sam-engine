<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega el valor 'other' al enum recipient_type en notification_recipients.
 * 
 * Laravel crea enums como CHECK constraints en PostgreSQL, no como tipos enum nativos.
 * Esta migración elimina el constraint viejo y crea uno nuevo con el valor adicional.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // PostgreSQL: Laravel usa CHECK constraints para enums
        // Primero eliminar el constraint existente
        DB::statement('ALTER TABLE notification_recipients DROP CONSTRAINT IF EXISTS notification_recipients_recipient_type_check');
        
        // Crear nuevo constraint con el valor 'other' agregado
        DB::statement("ALTER TABLE notification_recipients ADD CONSTRAINT notification_recipients_recipient_type_check CHECK (recipient_type::text = ANY (ARRAY['operator'::text, 'monitoring_team'::text, 'supervisor'::text, 'emergency'::text, 'dispatch'::text, 'other'::text]))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revertir al constraint original sin 'other'
        DB::statement('ALTER TABLE notification_recipients DROP CONSTRAINT IF EXISTS notification_recipients_recipient_type_check');
        DB::statement("ALTER TABLE notification_recipients ADD CONSTRAINT notification_recipients_recipient_type_check CHECK (recipient_type::text = ANY (ARRAY['operator'::text, 'monitoring_team'::text, 'supervisor'::text, 'emergency'::text, 'dispatch'::text]))");
    }
};
