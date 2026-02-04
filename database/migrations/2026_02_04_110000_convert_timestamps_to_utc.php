<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Migración para convertir todos los timestamps de America/Monterrey a UTC.
 * 
 * IMPORTANTE: Esta migración debe ejecutarse ANTES de cambiar el timezone de la app a UTC.
 * 
 * Los timestamps estaban guardados en timezone America/Monterrey (UTC-6/-5 con DST).
 * Esta migración los convierte a UTC para que el sistema opere correctamente en UTC.
 * 
 * PostgreSQL maneja correctamente el DST (horario de verano) al usar AT TIME ZONE.
 */
return new class extends Migration {
    /**
     * Timezone anterior de la aplicación
     */
    private const OLD_TIMEZONE = 'America/Monterrey';

    /**
     * Tablas y columnas a migrar.
     * Formato: 'tabla' => ['columna1', 'columna2', ...]
     */
    private array $tablesToMigrate = [
        // Core tables
        'users' => [
            'email_verified_at',
            'two_factor_confirmed_at',
            'created_at',
            'updated_at',
        ],
        'password_reset_tokens' => [
            'created_at',
        ],
        'personal_access_tokens' => [
            'last_used_at',
            'expires_at',
            'created_at',
            'updated_at',
        ],
        'companies' => [
            'created_at',
            'updated_at',
            'deleted_at',
        ],
        
        // Samsara events (tabla principal)
        'samsara_events' => [
            'occurred_at',
            'ai_processed_at',
            'last_investigation_at',
            'notification_sent_at',
            'reviewed_at',
            'created_at',
            'updated_at',
        ],
        'samsara_event_comments' => [
            'created_at',
            'updated_at',
        ],
        'samsara_event_activities' => [
            'created_at',
        ],
        
        // Contacts
        'contacts' => [
            'created_at',
            'updated_at',
            'deleted_at',
        ],
        
        // Fleet data
        'vehicles' => [
            'samsara_created_at',
            'samsara_updated_at',
            'created_at',
            'updated_at',
        ],
        'drivers' => [
            'samsara_created_at',
            'samsara_updated_at',
            'created_at',
            'updated_at',
        ],
        'tags' => [
            'created_at',
            'updated_at',
        ],
        'vehicle_stats' => [
            'gps_time',
            'engine_time',
            'odometer_time',
            'synced_at',
            'created_at',
            'updated_at',
        ],
        
        // Copilot
        'conversations' => [
            'created_at',
            'updated_at',
        ],
        'chat_messages' => [
            'streaming_started_at',
            'streaming_completed_at',
            'created_at',
            'updated_at',
        ],
        'token_usages' => [
            'created_at',
            'updated_at',
        ],
        
        // Safety signals & incidents
        'safety_signals' => [
            'occurred_at',
            'samsara_created_at',
            'samsara_updated_at',
            'created_at',
            'updated_at',
        ],
        'incidents' => [
            'detected_at',
            'resolved_at',
            'created_at',
            'updated_at',
        ],
        'incident_safety_signals' => [
            'created_at',
        ],
        
        // Event processing tables
        'event_recommended_actions' => [
            'created_at',
        ],
        'event_investigation_steps' => [
            'created_at',
        ],
        'event_metrics' => [
            'created_at',
        ],
        
        // Notification tables
        'notification_decisions' => [
            'created_at',
        ],
        'notification_recipients' => [
            'created_at',
        ],
        'notification_results' => [
            'timestamp_utc',
            'created_at',
        ],
        'notification_throttle_log' => [
            'notification_timestamp',
        ],
        'notification_dedupe_log' => [
            'first_seen_at',
            'last_seen_at',
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $migratedTables = 0;
        $migratedColumns = 0;

        foreach ($this->tablesToMigrate as $table => $columns) {
            // Verificar si la tabla existe
            if (!$this->tableExists($table)) {
                Log::info("UTC Migration: Skipping table '{$table}' (does not exist)");
                continue;
            }

            $existingColumns = $this->getExistingColumns($table, $columns);
            
            if (empty($existingColumns)) {
                Log::info("UTC Migration: Skipping table '{$table}' (no matching columns)");
                continue;
            }

            $this->convertTableToUtc($table, $existingColumns);
            $migratedTables++;
            $migratedColumns += count($existingColumns);
        }

        Log::info("UTC Migration completed: {$migratedTables} tables, {$migratedColumns} columns converted");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revertir: convertir de UTC a America/Monterrey
        foreach ($this->tablesToMigrate as $table => $columns) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $existingColumns = $this->getExistingColumns($table, $columns);
            
            if (empty($existingColumns)) {
                continue;
            }

            $this->convertTableFromUtc($table, $existingColumns);
        }

        Log::info("UTC Migration rollback completed: timestamps converted back to America/Monterrey");
    }

    /**
     * Convertir columnas de una tabla de Monterrey a UTC
     */
    private function convertTableToUtc(string $table, array $columns): void
    {
        $setClauses = [];
        
        foreach ($columns as $column) {
            // PostgreSQL: Interpretar como Monterrey y convertir a UTC
            // El resultado es el timestamp equivalente en UTC
            $setClauses[] = "{$column} = ({$column} AT TIME ZONE '" . self::OLD_TIMEZONE . "') AT TIME ZONE 'UTC'";
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $setClauses);
        
        DB::statement($sql);
        
        Log::info("UTC Migration: Converted table '{$table}' (" . implode(', ', $columns) . ")");
    }

    /**
     * Convertir columnas de una tabla de UTC a Monterrey (rollback)
     */
    private function convertTableFromUtc(string $table, array $columns): void
    {
        $setClauses = [];
        
        foreach ($columns as $column) {
            // Inverso: Interpretar como UTC y convertir a Monterrey
            $setClauses[] = "{$column} = ({$column} AT TIME ZONE 'UTC') AT TIME ZONE '" . self::OLD_TIMEZONE . "'";
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $setClauses);
        
        DB::statement($sql);
    }

    /**
     * Verificar si una tabla existe
     */
    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }

    /**
     * Obtener solo las columnas que existen en la tabla
     */
    private function getExistingColumns(string $table, array $columns): array
    {
        $existingColumns = [];
        
        foreach ($columns as $column) {
            if (DB::getSchemaBuilder()->hasColumn($table, $column)) {
                $existingColumns[] = $column;
            }
        }
        
        return $existingColumns;
    }
};
