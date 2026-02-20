<?php

namespace App\Console\Commands;

use App\Models\NotificationDedupeLog;
use Illuminate\Console\Command;

/**
 * Borra una o todas las claves de dedupe de notificaciones (solo para desarrollo/testing).
 * Permite re-enviar notificaciones para el mismo evento.
 */
class ClearNotificationDedupe extends Command
{
    protected $signature = 'notification:clear-dedupe
                            {key? : dedupe_key a borrar (ej: 281474992992629:2026-02-19T01:42:18Z:panic_button). Sin argumento lista las claves.)}
                            {--all : Borrar todas las claves (solo si APP_ENV=local)}
                            {--force : Permitir en cualquier entorno (usa con cuidado)}';

    protected $description = 'Borra claves de dedupe para permitir re-enviar notificaciones (desarrollo/testing)';

    public function handle(): int
    {
        $key = $this->argument('key');
        $all = $this->option('all');
        $force = $this->option('force');

        if (!$force && app()->environment('production')) {
            $this->error('Este comando no se puede ejecutar en producción sin --force.');
            return self::FAILURE;
        }

        if ($all) {
            $deleted = NotificationDedupeLog::query()->count();
            NotificationDedupeLog::query()->delete();
            $this->info("Se borraron {$deleted} clave(s) de dedupe.");
            return self::SUCCESS;
        }

        if ($key === null || $key === '') {
            $entries = NotificationDedupeLog::query()->orderByDesc('last_seen_at')->limit(50)->get();
            if ($entries->isEmpty()) {
                $this->info('No hay claves de dedupe registradas.');
                return self::SUCCESS;
            }
            $this->table(
                ['dedupe_key', 'first_seen_at', 'last_seen_at', 'count'],
                $entries->map(fn ($e) => [$e->dedupe_key, $e->first_seen_at?->toDateTimeString(), $e->last_seen_at?->toDateTimeString(), $e->count])
            );
            $this->info('Para borrar una clave: sail artisan notification:clear-dedupe "<dedupe_key>"');
            return self::SUCCESS;
        }

        $deleted = NotificationDedupeLog::where('dedupe_key', $key)->delete();
        if ($deleted > 0) {
            $this->info("Clave de dedupe borrada: {$key}");
        } else {
            $this->warn("No se encontró la clave: {$key}");
        }

        return self::SUCCESS;
    }
}
