<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAlertJob;
use App\Models\Alert;
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
    protected $signature = 'alerts:reprocess-failed-revalidations 
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

        $query = Alert::query()
            ->whereHas('ai', function ($q) {
                $q->where('investigation_count', '>', 0);
            })
            ->where(function ($q) {
                $q->whereIn('ai_status', [
                    Alert::STATUS_INVESTIGATING,
                    Alert::STATUS_FAILED,
                ]);
                
                $q->orWhere(function ($q2) {
                    $q2->where('ai_status', Alert::STATUS_COMPLETED)
                       ->whereNull('verdict');
                });
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

        $alerts = $query->with(['signal', 'ai'])->orderBy('occurred_at', 'desc')->get();

        if ($alerts->isEmpty()) {
            $this->info('No se encontraron alertas afectadas.');
            return Command::SUCCESS;
        }

        $this->info("Encontradas {$alerts->count()} alertas afectadas:");
        $this->newLine();

        // Mostrar tabla con las alertas
        $this->table(
            ['ID', 'Company', 'Vehicle', 'Status', 'Inv. Count', 'Error', 'Occurred At'],
            $alerts->map(fn ($a) => [
                $a->id,
                $a->company_id,
                $a->signal?->vehicle_name,
                $a->ai_status,
                $a->ai?->investigation_count ?? 0,
                str($a->ai?->ai_error)->limit(40),
                $a->occurred_at?->format('Y-m-d H:i'),
            ])
        );

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('Modo dry-run: No se realizaron cambios.');
            $this->info('Ejecuta sin --dry-run para reprocesar estas alertas.');
            return Command::SUCCESS;
        }

        $this->newLine();
        if (!$this->confirm("¿Reprocesar {$alerts->count()} alertas?")) {
            $this->info('Operación cancelada.');
            return Command::SUCCESS;
        }

        $this->newLine();
        $bar = $this->output->createProgressBar($alerts->count());
        $bar->start();

        $processed = 0;
        $errors = [];

        foreach ($alerts as $alert) {
            try {
                $alert->update([
                    'ai_status' => Alert::STATUS_PENDING,
                ]);

                if ($alert->ai) {
                    $alert->ai->update([
                        'ai_error' => null,
                        'investigation_count' => 0,
                        'last_investigation_at' => null,
                        'next_check_minutes' => null,
                    ]);
                }

                dispatch(new ProcessAlertJob($alert));

                $processed++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'alert_id' => $alert->id,
                    'error' => $e->getMessage(),
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Alertas reprocesadas: {$processed}/{$alerts->count()}");

        if (!empty($errors)) {
            $this->newLine();
            $this->error('Errores durante el reprocesamiento:');
            foreach ($errors as $err) {
                $this->line("  - Alert #{$err['alert_id']}: {$err['error']}");
            }
        }

        $this->newLine();
        $this->info('Los jobs de procesamiento han sido encolados.');
        $this->info('Monitorea el progreso con: sail artisan horizon');

        return Command::SUCCESS;
    }
}
