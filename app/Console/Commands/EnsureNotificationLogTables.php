<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comprueba si existen las tablas de notificaciones (dedupe/throttle) y opcionalmente las crea.
 * Útil cuando en prod dice "nothing to migrate" pero las tablas no existen (p. ej. se borraron o nunca se crearon).
 */
class EnsureNotificationLogTables extends Command
{
    protected $signature = 'notification:ensure-tables
                            {--fix : Crear las tablas que falten (sin tocar la tabla migrations)}
                            {--force : No pedir confirmación al usar --fix}';

    protected $description = 'Comprueba si existen notification_dedupe_logs y notification_throttle_logs; con --fix las crea si faltan';

    public function handle(): int
    {
        $dedupeExists = Schema::hasTable('notification_dedupe_logs');
        $throttleExists = Schema::hasTable('notification_throttle_logs');

        $this->table(
            ['Tabla', '¿Existe?'],
            [
                ['notification_dedupe_logs', $dedupeExists ? 'Sí' : 'No'],
                ['notification_throttle_logs', $throttleExists ? 'Sí' : 'No'],
            ]
        );

        if ($dedupeExists && $throttleExists) {
            $this->info('Todas las tablas existen. No hace falta hacer nada.');
            return self::SUCCESS;
        }

        if (! $this->option('fix')) {
            $this->newLine();
            $this->warn('Faltan tablas. Para crearlas sin ejecutar migraciones, usa:');
            $this->line('  php artisan notification:ensure-tables --fix');
            $this->newLine();
            $this->comment('(Esto crea solo las tablas que falten; no modifica la tabla migrations.)');
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('¿Crear las tablas que falten?', true)) {
            return self::SUCCESS;
        }

        $created = [];

        if (! $dedupeExists) {
            $this->createNotificationDedupeLogsTable();
            $created[] = 'notification_dedupe_logs';
        }

        if (! $throttleExists) {
            $this->createNotificationThrottleLogsTable();
            $created[] = 'notification_throttle_logs';
        }

        if ($created !== []) {
            $this->info('Tablas creadas: '.implode(', ', $created));
        }

        return self::SUCCESS;
    }

    private function createNotificationDedupeLogsTable(): void
    {
        Schema::create('notification_dedupe_logs', function (Blueprint $table) {
            $table->string('dedupe_key', 255)->primary();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->integer('count')->default(1);
            $table->index('last_seen_at');
        });
    }

    private function createNotificationThrottleLogsTable(): void
    {
        Schema::create('notification_throttle_logs', function (Blueprint $table) {
            $table->id();
            $table->string('throttle_key', 255);
            $table->timestamp('notification_timestamp');

            // alert_id: FK a alerts si la tabla existe (schema actual); si no, columna sin FK
            if (Schema::hasTable('alerts')) {
                $table->unsignedBigInteger('alert_id')->nullable();
                $table->foreign('alert_id')->references('id')->on('alerts')->nullOnDelete();
            } else {
                $table->unsignedBigInteger('alert_id')->nullable();
            }

            $table->index(['throttle_key', 'notification_timestamp']);
            $table->index('notification_timestamp');
        });
    }
}
