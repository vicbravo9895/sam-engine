<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSamsaraEventJob;
use App\Models\SamsaraEvent;
use Illuminate\Console\Command;

/**
 * Comando para reprocesar alertas que fallaron durante revalidación
 * debido al bug de "Context variable not found: alert_context".
 * 
 * Este bug afectaba a alertas que:
 * - Estaban en estado 'investigating' (requieren monitoreo continuo)
 * - Fallaron durante la revalidación porque el pipeline de revalidación
 *   no tenía la variable alert_context en el state de ADK
 */
class ReprocessFailedRevalidations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'samsara:reprocess-failed-revalidations 
                            {--dry-run : Solo mostrar las alertas afectadas sin reprocesar}
                            {--company= : Filtrar por company_id específico}
                            {--limit=100 : Límite de alertas a reprocesar}
                            {--since= : Solo alertas desde esta fecha (Y-m-d)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reprocesa alertas que fallaron durante revalidación por el bug de alert_context';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Buscando alertas afectadas por el bug de alert_context...');
        $this->newLine();

        $query = SamsaraEvent::query()
            // Alertas que estaban en investigating o failed
            ->whereIn('ai_status', [
                SamsaraEvent::STATUS_INVESTIGATING,
                SamsaraEvent::STATUS_FAILED,
            ])
            // Que tienen investigation_count > 0 (ya tuvieron al menos una investigación)
            ->where('investigation_count', '>', 0)
            // Que tienen el error específico del bug
            ->where(function ($q) {
                $q->where('ai_error', 'like', '%alert_context%')
                  ->orWhere('ai_error', 'like', '%Context variable not found%')
                  // O que no tienen assessment válido a pesar de tener investigaciones
                  ->orWhereNull('ai_assessment');
            });

        // Filtros opcionales
        $companyId = $this->option('company');
        if ($companyId) {
            $query->where('company_id', $companyId);
            $this->info("Filtrando por company_id: {$companyId}");
        }

        $since = $this->option('since');
        if ($since) {
            $query->where('occurred_at', '>=', $since);
            $this->info("Filtrando desde: {$since}");
        }

        $limit = (int) $this->option('limit');
        $query->limit($limit);

        $events = $query->orderBy('occurred_at', 'desc')->get();

        if ($events->isEmpty()) {
            $this->info('No se encontraron alertas afectadas.');
            return Command::SUCCESS;
        }

        $this->info("Encontradas {$events->count()} alertas afectadas:");
        $this->newLine();

        // Mostrar tabla con las alertas
        $this->table(
            ['ID', 'Company', 'Vehicle', 'Status', 'Inv. Count', 'Error', 'Occurred At'],
            $events->map(fn ($e) => [
                $e->id,
                $e->company_id,
                $e->vehicle_name,
                $e->ai_status,
                $e->investigation_count,
                str($e->ai_error)->limit(40),
                $e->occurred_at?->format('Y-m-d H:i'),
            ])
        );

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('Modo dry-run: No se realizaron cambios.');
            $this->info('Ejecuta sin --dry-run para reprocesar estas alertas.');
            return Command::SUCCESS;
        }

        $this->newLine();
        if (!$this->confirm("¿Reprocesar {$events->count()} alertas?")) {
            $this->info('Operación cancelada.');
            return Command::SUCCESS;
        }

        $this->newLine();
        $bar = $this->output->createProgressBar($events->count());
        $bar->start();

        $processed = 0;
        $errors = [];

        foreach ($events as $event) {
            try {
                // Resetear el evento para reprocesamiento
                $event->update([
                    'ai_status' => SamsaraEvent::STATUS_PENDING,
                    'ai_error' => null,
                    'investigation_count' => 0,
                    'last_investigation_at' => null,
                    'next_check_minutes' => null,
                    // Mantener el assessment original si existe para referencia
                    // pero limpiar los campos de error
                ]);

                // Despachar el job de procesamiento
                dispatch(new ProcessSamsaraEventJob($event));

                $processed++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'event_id' => $event->id,
                    'error' => $e->getMessage(),
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Alertas reprocesadas: {$processed}/{$events->count()}");

        if (!empty($errors)) {
            $this->newLine();
            $this->error('Errores durante el reprocesamiento:');
            foreach ($errors as $err) {
                $this->line("  - Event #{$err['event_id']}: {$err['error']}");
            }
        }

        $this->newLine();
        $this->info('Los jobs de procesamiento han sido encolados.');
        $this->info('Monitorea el progreso con: sail artisan horizon');

        return Command::SUCCESS;
    }
}
