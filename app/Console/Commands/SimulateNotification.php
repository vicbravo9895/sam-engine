<?php

namespace App\Console\Commands;

use App\Jobs\SendNotificationJob;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Signal;
use App\Services\TwilioService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Comando para simular notificaciones y validar el pipeline.
 * 
 * Permite:
 * - Simular una decisión de notificación del agente AI
 * - Ejecutar notificaciones via SendNotificationJob o TwilioService directamente
 * - Verificar que el sistema de notificaciones funciona correctamente
 */
class SimulateNotification extends Command
{
    protected $signature = 'notification:simulate 
                            {--company= : ID de la empresa}
                            {--channel=sms : Canal a usar (sms, whatsapp, call)}
                            {--to= : Número de teléfono destino (formato E.164)}
                            {--message= : Mensaje personalizado}
                            {--escalation=low : Nivel de escalación (critical, high, low, none)}
                            {--event= : ID de un evento existente para usar como contexto}
                            {--sync : Ejecutar sincrónicamente usando TwilioService directamente}
                            {--dry-run : No enviar realmente, solo mostrar qué se haría}';

    protected $description = 'Simula una notificación para validar el pipeline de notificaciones';

    /**
     * Mensajes de prueba por tipo de alerta.
     */
    private array $testMessages = [
        'panic_button' => 'ALERTA: Botón de pánico activado en vehículo T-012021. Ubicación: Av. Insurgentes 1234, CDMX. Conductor: Juan Pérez. Verificar inmediatamente.',
        'harsh_braking' => 'Frenado brusco detectado en T-012021. Velocidad: 85 km/h a 20 km/h en 2.3s. Ubicación: Periférico Norte Km 45.',
        'speeding' => 'Exceso de velocidad en T-012021. Velocidad actual: 120 km/h (límite: 80 km/h). Conductor: María García.',
        'collision' => 'POSIBLE COLISIÓN detectada en T-012021. Impacto registrado a las 14:35. Ubicación: Carretera México-Querétaro Km 89. Verificar estado del conductor.',
        'test' => 'Prueba de notificación SAM. Si recibiste este mensaje, el sistema funciona correctamente.',
    ];

    public function handle(TwilioService $twilioService): int
    {
        $this->info('');
        $this->info('========================================');
        $this->info('  SAM - Simulador de Notificaciones');
        $this->info('========================================');
        $this->info('');

        // Validar configuración de Twilio
        if (!$twilioService->isConfigured()) {
            $this->error('Twilio no está configurado.');
            $this->info('');
            $this->info('Agrega a tu .env:');
            $this->line('  TWILIO_ACCOUNT_SID=ACxxxxx');
            $this->line('  TWILIO_AUTH_TOKEN=xxxxx');
            $this->line('  TWILIO_PHONE_NUMBER=+1xxxxxxxxxx');
            $this->line('  TWILIO_WHATSAPP_NUMBER=whatsapp:+1xxxxxxxxxx');
            return Command::FAILURE;
        }

        $this->info('Twilio configurado correctamente.');
        $this->info('');

        // Obtener contexto
        $context = $this->buildContext();
        if (!$context) {
            return Command::FAILURE;
        }

        $this->displayContext($context);

        // Dry run - solo mostrar
        if ($this->option('dry-run')) {
            $this->warn('MODO DRY-RUN: No se enviará ninguna notificación.');
            $this->info('');
            $this->info('Para enviar la notificación, ejecuta sin --dry-run');
            return Command::SUCCESS;
        }

        // Confirmar
        if (!$this->confirm('¿Enviar la notificación?')) {
            $this->info('Operación cancelada.');
            return Command::SUCCESS;
        }

        // Ejecutar
        if ($this->option('sync')) {
            return $this->executeSyncNotification($twilioService, $context);
        }

        return $this->dispatchNotificationJob($context);
    }

    /**
     * Construye el contexto para la simulación.
     */
    private function buildContext(): ?array
    {
        // Si hay un evento específico, usarlo
        if ($eventId = $this->option('event')) {
            $alert = Alert::find($eventId);
            if (!$alert) {
                $this->error("Alerta con ID {$eventId} no encontrada.");
                return null;
            }
            return $this->buildContextFromAlert($alert);
        }

        // Obtener empresa
        $companyId = $this->option('company');
        if (!$companyId) {
            $company = Company::first();
            if (!$company) {
                $this->error('No hay empresas en la BD. Especifica --company=ID');
                return null;
            }
            $companyId = $company->id;
        }

        $company = Company::find($companyId);
        if (!$company) {
            $this->error("Empresa con ID {$companyId} no encontrada.");
            return null;
        }

        // Obtener número destino
        $to = $this->option('to');
        if (!$to) {
            $contact = Contact::where('company_id', $companyId)->first();
            if ($contact && $contact->phone) {
                $to = $contact->phone;
                $this->info("Usando número del contacto '{$contact->name}': {$to}");
            } else {
                $to = $this->ask('Número de teléfono destino (formato E.164, ej: +5218117658890)');
                if (!$to) {
                    $this->error('Se requiere un número de teléfono destino.');
                    return null;
                }
            }
        }

        // Seleccionar mensaje
        $message = $this->option('message');
        if (!$message) {
            $messageType = $this->choice(
                'Tipo de mensaje de prueba',
                array_keys($this->testMessages),
                'test'
            );
            $message = $this->testMessages[$messageType];
        }

        return [
            'company_id' => $companyId,
            'company_name' => $company->name,
            'channel' => $this->option('channel'),
            'to' => $to,
            'message' => $message,
            'escalation_level' => $this->option('escalation'),
            'alert' => null,
        ];
    }

    /**
     * Construye contexto desde un evento existente.
     */
    private function buildContextFromAlert(Alert $alert): array
    {
        $to = $this->option('to');
        
        if (!$to) {
            $contact = Contact::where('company_id', $alert->company_id)->first();
            $to = $contact?->phone;
            
            if (!$to) {
                $to = $this->ask('Número de teléfono destino');
            }
        }

        $message = $this->option('message') ?? $alert->ai_message ?? $this->testMessages['test'];

        return [
            'company_id' => $alert->company_id,
            'company_name' => $alert->company?->name ?? 'N/A',
            'channel' => $this->option('channel'),
            'to' => $to,
            'message' => $message,
            'escalation_level' => $this->option('escalation'),
            'alert' => $alert,
        ];
    }

    /**
     * Muestra el contexto de la simulación.
     */
    private function displayContext(array $context): void
    {
        $this->info('Contexto de simulación:');
        $this->table(
            ['Campo', 'Valor'],
            [
                ['Empresa', "{$context['company_name']} (ID: {$context['company_id']})"],
                ['Canal', $context['channel']],
                ['Destino', $context['to']],
                ['Escalación', $context['escalation_level']],
                ['Alerta', $context['alert']?->id ?? 'Ninguno (simulación pura)'],
                ['Mensaje', mb_substr($context['message'], 0, 80) . (mb_strlen($context['message']) > 80 ? '...' : '')],
            ]
        );
        $this->info('');
    }

    /**
     * Ejecuta la notificación sincrónicamente usando TwilioService.
     */
    private function executeSyncNotification(TwilioService $twilioService, array $context): int
    {
        $this->info('Ejecutando notificación sincrónicamente via TwilioService...');
        $this->info('');

        $channel = $context['channel'];
        $to = $context['to'];
        $message = $context['message'];

        $result = match ($channel) {
            'sms' => $twilioService->sendSms($to, $message),
            'whatsapp' => $twilioService->sendWhatsapp($to, $message),
            'call' => $twilioService->makeCall($to, $message),
            default => ['success' => false, 'error' => "Canal '{$channel}' no soportado"],
        };

        $this->displayResult($result);

        return ($result['success'] ?? false) ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Despacha SendNotificationJob.
     */
    private function dispatchNotificationJob(array $context): int
    {
        $alert = $context['alert'];
        
        if (!$alert) {
            $this->info('Creando alerta temporal para simulación...');
            
            $signal = Signal::create([
                'company_id' => $context['company_id'],
                'event_type' => 'TestNotification',
                'event_description' => 'Simulación de notificación',
                'samsara_event_id' => 'test_' . now()->timestamp . '_' . uniqid(),
                'vehicle_id' => 'test-vehicle',
                'vehicle_name' => 'T-TEST',
                'severity' => 'info',
                'occurred_at' => now(),
                'raw_payload' => ['test' => true, 'simulated' => true],
            ]);

            $alert = Alert::create([
                'company_id' => $context['company_id'],
                'signal_id' => $signal->id,
                'event_description' => 'Simulación de notificación',
                'severity' => 'info',
                'occurred_at' => now(),
                'ai_status' => Alert::STATUS_COMPLETED,
            ]);

            $this->info("Alerta temporal creada: ID {$alert->id}");
        }

        // Construir decisión de notificación
        $decision = [
            'should_notify' => true,
            'channels_to_use' => [$context['channel']],
            'escalation_level' => $context['escalation_level'],
            'message_text' => $context['message'],
            'call_script' => mb_substr($context['message'], 0, 200),
            'recipients' => [
                [
                    'recipient_type' => 'operator',  // Usar tipo válido
                    'phone' => $context['to'],
                    'whatsapp' => $context['to'],
                    'priority' => 1,
                ],
            ],
            'dedupe_key' => 'test_' . now()->timestamp . '_' . uniqid(),
            'reason' => 'Simulación de prueba desde CLI',
        ];

        $this->info('Despachando SendNotificationJob...');
        $this->info('');
        $this->info('Decisión de notificación:');
        $this->line(json_encode($decision, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('');

        SendNotificationJob::dispatch($alert, $decision);

        $this->info('Job despachado exitosamente.');
        $this->info('');
        $this->info('El job se ejecutará en la cola "notifications".');
        $this->info('Monitorea el resultado en:');
        $this->line('  - Horizon: /horizon');
        $this->line('  - Logs: storage/logs/laravel.log');
        $this->line('  - BD: notification_results, notification_decisions');
        $this->info('');
        $this->info("Alerta ID: {$alert->id}");

        return Command::SUCCESS;
    }

    /**
     * Muestra el resultado de una notificación.
     */
    private function displayResult(array $result): void
    {
        $success = $result['success'] ?? false;
        $channel = strtoupper($result['channel'] ?? 'unknown');
        $to = $result['to'] ?? 'N/A';

        if ($success) {
            $this->info("Resultado: {$channel} -> {$to}");
            
            if (isset($result['sid'])) {
                $this->line("  SID: {$result['sid']}");
            }
            if (isset($result['status'])) {
                $this->line("  Status: {$result['status']}");
            }
        } else {
            $this->error("Error: {$channel} -> {$to}");
            $this->error("  " . ($result['error'] ?? 'Error desconocido'));
        }
    }
}
