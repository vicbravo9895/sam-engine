<?php

namespace App\Console\Commands;

use App\Jobs\SendNotificationJob;
use App\Models\Alert;
use Illuminate\Console\Command;

/**
 * Re-dispatch notifications for an existing alert using its stored decision payload.
 * Use this to retry after fixing contact resolution or configuration (e.g. phone/whatsapp on contacts).
 *
 * Example: sail artisan notification:retry 193
 */
class RetryAlertNotifications extends Command
{
    protected $signature = 'notification:retry
                            {alert : ID de la alerta}
                            {--dry-run : Solo mostrar la decisión que se enviaría}';

    protected $description = 'Reenvía notificaciones para una alerta usando el payload de decisión guardado';

    public function handle(): int
    {
        $alertId = $this->argument('alert');
        $alert = Alert::with('signal')->find($alertId);

        if (!$alert) {
            $this->error("Alerta con ID {$alertId} no encontrada.");
            return self::FAILURE;
        }

        $payload = $alert->notification_decision_payload;
        if (!is_array($payload) || empty($payload)) {
            $this->error("La alerta {$alertId} no tiene notification_decision_payload guardado (o está vacío).");
            return self::FAILURE;
        }

        if (!($payload['should_notify'] ?? false)) {
            $this->warn('La decisión guardada tiene should_notify=false. Se reenviará igual; el job puede no enviar si respeta la decisión.');
        }

        $this->info("Alerta {$alert->id} — Empresa {$alert->company_id} — Estado notificación: {$alert->notification_status}");
        $this->line('Canales: ' . implode(', ', $payload['channels_to_use'] ?? []));
        $this->line('Destinatarios (tipos): ' . implode(', ', array_column($payload['recipients'] ?? [], 'recipient_type')));

        if ($this->option('dry-run')) {
            $this->info('Dry-run: no se despachó el job.');
            return self::SUCCESS;
        }

        SendNotificationJob::dispatch($alert, $payload);
        $this->info('SendNotificationJob despachado. Revisa la cola "notifications" y los logs.');
        return self::SUCCESS;
    }
}
