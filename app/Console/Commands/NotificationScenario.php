<?php

namespace App\Console\Commands;

use App\Jobs\SendNotificationJob;
use App\Models\Alert;
use Illuminate\Console\Command;

/**
 * Monta un escenario de notificación completo: usa una alerta existente e inyecta
 * los números que quieras (operador, monitoreo, supervisor) para recibir llamada y WhatsApp.
 *
 * Ejemplo: simular que tú eres el operador y recibir call + whatsapp de la alerta 193:
 *   sail artisan notification:scenario 193 --operator=+528117658890
 *
 * Si solo pasas --operator, ese número se usa también para monitoreo y supervisor
 * (recibes todas las notificaciones en un solo teléfono para pruebas).
 */
class NotificationScenario extends Command
{
    protected $signature = 'notification:scenario
                            {alert : ID de la alerta (ej. 193)}
                            {--operator= : Número del operador (E.164, ej. +528117658890)}
                            {--monitoring= : Número equipo monitoreo (opcional; si no se pasa, se usa el de operator)}
                            {--supervisor= : Número supervisor (opcional; si no se pasa, se usa el de operator)}
                            {--channels=call,whatsapp : Canales a usar (call, whatsapp, sms)}
                            {--dry-run : Solo mostrar la decisión, no despachar}';

    protected $description = 'Escenario de notificación: inyecta números en una alerta y despacha call + whatsapp';

    public function handle(): int
    {
        $alertId = $this->argument('alert');
        $operator = $this->option('operator');
        $monitoring = $this->option('monitoring') ?: $operator;
        $supervisor = $this->option('supervisor') ?: $operator;

        if (!$operator) {
            $this->error('Indica al menos --operator=+528117658890 (o el número al que quieres recibir la notificación).');
            return self::FAILURE;
        }

        $alert = Alert::with('signal')->find($alertId);
        if (!$alert) {
            $this->error("Alerta con ID {$alertId} no encontrada.");
            return self::FAILURE;
        }

        $channels = array_map('trim', explode(',', $this->option('channels')));
        $channels = array_values(array_intersect($channels, ['call', 'whatsapp', 'sms']));
        if (empty($channels)) {
            $channels = ['call', 'whatsapp'];
        }

        $payload = $alert->notification_decision_payload;
        $messageText = is_array($payload) ? ($payload['message_text'] ?? $alert->ai_message) : $alert->ai_message;
        $callScript = is_array($payload) ? ($payload['call_script'] ?? mb_substr($messageText, 0, 200)) : mb_substr($messageText ?? '', 0, 200);
        $escalationLevel = is_array($payload) ? ($payload['escalation_level'] ?? 'high') : 'high';

        $recipients = [];
        if ($operator) {
            $recipients[] = [
                'recipient_type' => 'operator',
                'phone' => $operator,
                'whatsapp' => $operator,
                'priority' => 1,
            ];
        }
        if ($monitoring) {
            $recipients[] = [
                'recipient_type' => 'monitoring_team',
                'phone' => $monitoring,
                'whatsapp' => $monitoring,
                'priority' => 2,
            ];
        }
        if ($supervisor) {
            $recipients[] = [
                'recipient_type' => 'supervisor',
                'phone' => $supervisor,
                'whatsapp' => $supervisor,
                'priority' => 3,
            ];
        }

        $decision = [
            'should_notify' => true,
            'escalation_level' => $escalationLevel,
            'channels_to_use' => $channels,
            'message_text' => $messageText ?? 'Alerta de prueba',
            'call_script' => $callScript,
            'recipients' => $recipients,
            'dedupe_key' => 'scenario_' . $alert->id . '_' . now()->timestamp,
            'reason' => 'Escenario de simulación vía notification:scenario',
        ];

        $this->info('');
        $this->info('========================================');
        $this->info('  Escenario de notificación');
        $this->info('========================================');
        $this->table(
            ['Campo', 'Valor'],
            [
                ['Alerta', "{$alert->id} ({$alert->event_description})"],
                ['Operador', $operator],
                ['Monitoreo', $monitoring ?: '(mismo que operador)'],
                ['Supervisor', $supervisor ?: '(mismo que operador)'],
                ['Canales', implode(', ', $channels)],
            ]
        );
        $this->line('Mensaje (resumen): ' . mb_substr($messageText ?? '', 0, 80) . '...');
        $this->info('========================================');
        $this->info('');

        if ($this->option('dry-run')) {
            $this->warn('Dry-run: decisión que se enviaría:');
            $this->line(json_encode($decision, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        SendNotificationJob::dispatch($alert, $decision);
        $this->info('SendNotificationJob despachado. Deberías recibir llamada y WhatsApp en <operator>.');
        $this->line('Cola: notifications. Logs: storage/logs/laravel.log');
        return self::SUCCESS;
    }
}
