<?php

namespace App\Jobs;

use App\Models\NotificationDecision;
use App\Models\NotificationRecipient;
use App\Models\NotificationResult;
use App\Models\SamsaraEvent;
use App\Pulse\Recorders\NotificationRecorder;
use App\Services\NotificationDedupeService;
use App\Services\TwilioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job para enviar notificaciones via Twilio.
 * 
 * Recibe la decisión del AI Service y ejecuta las notificaciones
 * con idempotencia (dedupe) y throttling.
 * 
 * Los resultados se persisten en:
 * - notification_decisions
 * - notification_recipients
 * - notification_results
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de intentos antes de fallar
     */
    public $tries = 3;

    /**
     * Timeout en segundos
     */
    public $timeout = 120;

    /**
     * Tiempo de espera entre reintentos (en segundos)
     */
    public $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     *
     * @param SamsaraEvent $event El evento de Samsara
     * @param array $decision La decisión de notificación del AI Service
     */
    public function __construct(
        public SamsaraEvent $event,
        public array $decision
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     */
    public function handle(
        TwilioService $twilioService,
        NotificationDedupeService $dedupeService
    ): void {
        $startTime = microtime(true);

        Log::info('SendNotificationJob: Iniciando', [
            'event_id' => $this->event->id,
            'should_notify' => $this->decision['should_notify'] ?? false,
            'escalation_level' => $this->decision['escalation_level'] ?? 'none',
            'channels' => $this->decision['channels_to_use'] ?? [],
            'attempt' => $this->attempts(),
        ]);

        // Verificar si debe notificar
        if (!($this->decision['should_notify'] ?? false)) {
            Log::info('SendNotificationJob: No debe notificar', [
                'event_id' => $this->event->id,
                'reason' => $this->decision['reason'] ?? 'should_notify is false',
            ]);
            $this->persistDecisionOnly();
            return;
        }

        // Verificar dedupe y throttle
        $dedupeKey = $this->decision['dedupe_key'] ?? '';
        $checkResult = $dedupeService->shouldSend(
            dedupeKey: $dedupeKey,
            vehicleId: $this->event->vehicle_id,
            driverId: $this->event->driver_id,
            eventId: $this->event->id
        );

        if (!$checkResult['should_send']) {
            Log::info('SendNotificationJob: Bloqueado por dedupe/throttle', [
                'event_id' => $this->event->id,
                'reason' => $checkResult['reason'],
                'throttled' => $checkResult['throttled'],
            ]);
            
            $this->persistDecisionWithThrottle($checkResult);
            return;
        }

        // Persistir la decisión
        $notificationDecision = $this->persistDecision();

        // Ejecutar notificaciones
        $results = $this->sendNotifications($twilioService, $notificationDecision);

        // Persistir resultados
        $this->persistResults($results);

        // Actualizar estado del evento
        $this->updateEventNotificationStatus($results);

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('SendNotificationJob: Completado', [
            'event_id' => $this->event->id,
            'total_notifications' => count($results),
            'successful' => collect($results)->where('success', true)->count(),
            'failed' => collect($results)->where('success', false)->count(),
            'duration_ms' => $duration,
        ]);

        // Registrar métricas de notificación en Pulse
        foreach ($results as $result) {
            NotificationRecorder::recordNotification(
                channel: $result['channel'] ?? 'unknown',
                success: $result['success'] ?? false,
                durationMs: (int) $duration,
                companyId: $this->event->company_id,
                escalationLevel: $this->decision['escalation_level'] ?? 'high'
            );
        }
    }

    /**
     * Envía las notificaciones según la decisión.
     * 
     * Los canales se filtran según la configuración de la empresa.
     * Esto permite que cada empresa habilite/deshabilite canales específicos.
     * 
     * Orden: call > whatsapp > sms
     */
    private function sendNotifications(
        TwilioService $twilioService,
        NotificationDecision $notificationDecision
    ): array {
        $results = [];
        
        $channels = $this->decision['channels_to_use'] ?? [];
        $recipients = $this->decision['recipients'] ?? [];
        $messageText = $this->decision['message_text'] ?? '';
        $callScript = $this->decision['call_script'] ?? mb_substr($messageText, 0, 200);
        $escalationLevel = $this->decision['escalation_level'] ?? 'low';

        // Filtrar canales según la configuración de la empresa
        $channels = $this->filterChannelsByCompanyConfig($channels);
        
        if (empty($channels)) {
            Log::info('SendNotificationJob: No hay canales habilitados después del filtro', [
                'event_id' => $this->event->id,
                'original_channels' => $this->decision['channels_to_use'] ?? [],
                'company_id' => $this->event->company_id,
            ]);
            return $results;
        }

        // Ordenar recipients por prioridad
        usort($recipients, fn($a, $b) => ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999));

        // Ejecutar en orden: call > whatsapp > sms
        foreach (['call', 'whatsapp', 'sms'] as $channel) {
            if (!in_array($channel, $channels)) {
                continue;
            }

            foreach ($recipients as $recipient) {
                $result = $this->sendToRecipient(
                    twilioService: $twilioService,
                    channel: $channel,
                    recipient: $recipient,
                    message: $messageText,
                    callScript: $callScript,
                    escalationLevel: $escalationLevel
                );

                if ($result) {
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    /**
     * Filtra los canales de notificación según la configuración de la empresa.
     * 
     * La AI siempre emite su veredicto con los canales recomendados,
     * pero la empresa puede tener canales deshabilitados.
     * 
     * @param array $channels Canales recomendados por la AI
     * @return array Canales filtrados según config de empresa
     */
    private function filterChannelsByCompanyConfig(array $channels): array
    {
        $company = $this->event->company;
        
        if (!$company) {
            // Sin empresa, usar todos los canales
            return $channels;
        }

        // Mapeo de nombres de canales AI -> config de empresa
        $channelMapping = [
            'call' => 'call',
            'whatsapp' => 'whatsapp',
            'sms' => 'sms',
            'email' => 'email',
        ];

        $filteredChannels = [];
        $disabledChannels = [];

        foreach ($channels as $channel) {
            $configKey = $channelMapping[$channel] ?? $channel;
            $isEnabled = $company->isNotificationChannelEnabled($configKey);
            
            if ($isEnabled) {
                $filteredChannels[] = $channel;
            } else {
                $disabledChannels[] = $channel;
            }
        }

        // Log si se filtraron canales
        if (!empty($disabledChannels)) {
            Log::info('SendNotificationJob: Canales filtrados por config de empresa', [
                'event_id' => $this->event->id,
                'company_id' => $company->id,
                'original_channels' => $channels,
                'filtered_channels' => $filteredChannels,
                'disabled_channels' => $disabledChannels,
            ]);
        }

        return $filteredChannels;
    }

    /**
     * Envía una notificación a un destinatario específico.
     */
    private function sendToRecipient(
        TwilioService $twilioService,
        string $channel,
        array $recipient,
        string $message,
        string $callScript,
        string $escalationLevel
    ): ?array {
        $recipientType = $recipient['recipient_type'] ?? 'unknown';
        $phone = $recipient['phone'] ?? null;
        $whatsapp = $recipient['whatsapp'] ?? null;

        $result = null;

        if ($channel === 'call' && $phone) {
            // Botón de pánico usa callback especial
            $isPanic = $this->isPanicButtonEvent();
            
            if ($isPanic && in_array($escalationLevel, ['critical', 'high'])) {
                $response = $twilioService->makePanicCallWithCallback(
                    to: $phone,
                    vehicleName: $this->event->vehicle_name ?? 'Unidad',
                    eventId: $this->event->id
                );
            } elseif (in_array($escalationLevel, ['critical', 'high'])) {
                $response = $twilioService->makeCallWithCallback(
                    to: $phone,
                    message: $callScript,
                    eventId: $this->event->id
                );
            } else {
                $response = $twilioService->makeCall(
                    to: $phone,
                    message: $callScript
                );
            }

            $result = [
                'channel' => 'call',
                'to' => $phone,
                'recipient_type' => $recipientType,
                'success' => $response['success'] ?? false,
                'error' => $response['error'] ?? null,
                'call_sid' => $response['sid'] ?? null,
                'message_sid' => null,
            ];

        } elseif ($channel === 'whatsapp' && ($whatsapp || $phone)) {
            $target = $whatsapp ?: $phone;
            
            // Usar WhatsApp Template para poder iniciar conversaciones
            $templateSid = $this->getWhatsAppTemplateSid();
            $templateVars = $this->buildWhatsAppTemplateVariables();
            
            $response = $twilioService->sendWhatsappTemplate(
                to: $target,
                templateSid: $templateSid,
                variables: $templateVars
            );

            $result = [
                'channel' => 'whatsapp_template',
                'to' => $target,
                'recipient_type' => $recipientType,
                'success' => $response['success'] ?? false,
                'error' => $response['error'] ?? null,
                'call_sid' => null,
                'message_sid' => $response['sid'] ?? null,
                'template_sid' => $templateSid,
            ];

        } elseif ($channel === 'sms' && $phone) {
            $response = $twilioService->sendSms(
                to: $phone,
                message: $message
            );

            $result = [
                'channel' => 'sms',
                'to' => $phone,
                'recipient_type' => $recipientType,
                'success' => $response['success'] ?? false,
                'error' => $response['error'] ?? null,
                'call_sid' => null,
                'message_sid' => $response['sid'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Determina si el evento es un botón de pánico.
     */
    private function isPanicButtonEvent(): bool
    {
        $eventType = strtolower($this->event->event_type ?? '');
        $description = strtolower($this->event->event_description ?? '');
        
        return str_contains($eventType, 'panic') || 
               str_contains($description, 'pánico') ||
               str_contains($description, 'panic');
    }

    /**
     * Selecciona el template de WhatsApp apropiado según el tipo de evento.
     */
    private function getWhatsAppTemplateSid(): string
    {
        $eventType = $this->event->event_type ?? '';
        $description = $this->event->event_description ?? '';
        $combined = strtolower($eventType . ' ' . $description);
        
        // Botón de pánico / emergencias
        if (str_contains($combined, 'panic') || str_contains($combined, 'pánico') || str_contains($combined, 'emergency')) {
            return TwilioService::TEMPLATE_EMERGENCY_ALERT;
        }
        
        // Eventos de seguridad (colisiones, frenadas, etc.)
        if (str_contains($combined, 'collision') || 
            str_contains($combined, 'colisión') ||
            str_contains($combined, 'harsh') ||
            str_contains($combined, 'frenada') ||
            str_contains($combined, 'crash') ||
            str_contains($combined, 'safety')) {
            return TwilioService::TEMPLATE_SAFETY_ALERT;
        }
        
        // Por defecto: alerta de flota genérica
        return TwilioService::TEMPLATE_FLEET_ALERT;
    }

    /**
     * Construye las variables para el template de WhatsApp.
     * 
     * Las variables dependen del template pero generalmente siguen este patrón:
     * {{1}} = Tipo de alerta / Título
     * {{2}} = Nombre del vehículo
     * {{3}} = Nombre del conductor
     * {{4}} = Severidad o ubicación
     * {{5}} = Mensaje del AI / detalles
     * {{6}} = Timestamp
     */
    private function buildWhatsAppTemplateVariables(): array
    {
        $event = $this->event;
        $timezone = $event->company?->timezone ?? 'America/Mexico_City';
        $occurredAt = $event->occurred_at?->setTimezone($timezone)->format('d/m/Y H:i') ?? 'N/A';
        
        // Obtener el mensaje del AI o un fallback
        $aiMessage = $this->decision['message_text'] ?? $event->ai_message ?? 'Revisar evento';
        // Truncar si es muy largo (límite de templates ~1024 chars por variable)
        $aiMessage = mb_substr($aiMessage, 0, 500);
        
        // Extraer ubicación si está disponible
        $location = $this->extractLocationFromPayload();
        
        // Severidad legible
        $severity = match($event->severity ?? 'info') {
            'critical' => 'Crítico',
            'warning' => 'Alto',
            'info' => 'Medio',
            default => $event->severity ?? 'Info',
        };

        $templateSid = $this->getWhatsAppTemplateSid();
        
        // Variables específicas por template
        if ($templateSid === TwilioService::TEMPLATE_EMERGENCY_ALERT) {
            return [
                '1' => $event->vehicle_name ?? 'Unidad desconocida',
                '2' => $event->driver_name ?? 'No identificado',
                '3' => $location,
                '4' => $occurredAt,
                '5' => $aiMessage,
            ];
        }
        
        if ($templateSid === TwilioService::TEMPLATE_SAFETY_ALERT) {
            return [
                '1' => $event->event_description ?? 'Evento de Seguridad',
                '2' => $event->vehicle_name ?? 'Unidad desconocida',
                '3' => $event->driver_name ?? 'No identificado',
                '4' => $severity,
                '5' => $aiMessage,
                '6' => $occurredAt,
            ];
        }
        
        // TEMPLATE_FLEET_ALERT (default)
        return [
            '1' => $event->event_description ?? $event->event_type ?? 'Alerta de Flota',
            '2' => $event->vehicle_name ?? 'Unidad desconocida',
            '3' => $event->driver_name ?? 'No identificado',
            '4' => $occurredAt,
            '5' => $aiMessage,
        ];
    }

    /**
     * Extrae la ubicación del payload del evento.
     */
    private function extractLocationFromPayload(): string
    {
        $payload = $this->event->raw_payload ?? [];
        
        if (isset($payload['data']['location']['formattedAddress'])) {
            return $payload['data']['location']['formattedAddress'];
        }
        
        if (isset($payload['data']['location']['latitude'], $payload['data']['location']['longitude'])) {
            $lat = round($payload['data']['location']['latitude'], 6);
            $lng = round($payload['data']['location']['longitude'], 6);
            return "{$lat}, {$lng}";
        }
        
        return 'Ubicación no disponible';
    }

    /**
     * Persiste la decisión de notificación.
     * Usa updateOrCreate para manejar reintentos del job.
     */
    private function persistDecision(): NotificationDecision
    {
        $decision = NotificationDecision::updateOrCreate(
            ['samsara_event_id' => $this->event->id],
            [
                'should_notify' => $this->decision['should_notify'] ?? false,
                'escalation_level' => $this->decision['escalation_level'] ?? 'none',
                'message_text' => $this->decision['message_text'] ?? null,
                'call_script' => $this->decision['call_script'] ?? null,
                'reason' => $this->decision['reason'] ?? null,
            ]
        );

        // Limpiar recipients anteriores (en caso de reintento) y crear nuevos
        $decision->recipients()->delete();
        
        // Tipos válidos de recipient según la migración
        $validRecipientTypes = ['operator', 'monitoring_team', 'supervisor', 'emergency', 'dispatch', 'other'];
        
        foreach ($this->decision['recipients'] ?? [] as $recipientData) {
            $recipientType = $recipientData['recipient_type'] ?? 'other';
            // Usar 'other' si el tipo no es válido
            if (!in_array($recipientType, $validRecipientTypes)) {
                $recipientType = 'other';
            }
            
            NotificationRecipient::create([
                'notification_decision_id' => $decision->id,
                'recipient_type' => $recipientType,
                'phone' => $recipientData['phone'] ?? null,
                'whatsapp' => $recipientData['whatsapp'] ?? null,
                'priority' => $recipientData['priority'] ?? 999,
            ]);
        }

        return $decision;
    }

    /**
     * Persiste solo la decisión cuando no debe notificar.
     * Usa updateOrCreate para manejar reintentos del job.
     */
    private function persistDecisionOnly(): void
    {
        NotificationDecision::updateOrCreate(
            ['samsara_event_id' => $this->event->id],
            [
                'should_notify' => false,
                'escalation_level' => $this->decision['escalation_level'] ?? 'none',
                'message_text' => $this->decision['message_text'] ?? null,
                'call_script' => $this->decision['call_script'] ?? null,
                'reason' => $this->decision['reason'] ?? 'should_notify is false',
            ]
        );
    }

    /**
     * Persiste la decisión cuando fue bloqueada por throttle/dedupe.
     * Usa updateOrCreate para manejar reintentos del job.
     */
    private function persistDecisionWithThrottle(array $checkResult): void
    {
        NotificationDecision::updateOrCreate(
            ['samsara_event_id' => $this->event->id],
            [
                'should_notify' => true, // Quería notificar pero fue bloqueado
                'escalation_level' => $this->decision['escalation_level'] ?? 'none',
                'message_text' => $this->decision['message_text'] ?? null,
                'call_script' => $this->decision['call_script'] ?? null,
                'reason' => $checkResult['reason'] ?? 'Bloqueado por dedupe/throttle',
            ]
        );

        // Actualizar evento para indicar que fue throttled
        $this->event->update([
            'notification_status' => $checkResult['throttled'] ? 'throttled' : 'dedupe_blocked',
        ]);
    }

    /**
     * Persiste los resultados de notificación.
     */
    private function persistResults(array $results): void
    {
        foreach ($results as $result) {
            $channel = $result['channel'] === 'whatsapp_template' ? 'whatsapp' : $result['channel'];

            NotificationResult::create([
                'samsara_event_id' => $this->event->id,
                'channel' => $channel,
                'recipient_type' => $result['recipient_type'] ?? 'unknown',
                'to_number' => $result['to'],
                'success' => $result['success'],
                'error' => $result['error'],
                'call_sid' => $result['call_sid'],
                'message_sid' => $result['message_sid'],
                'timestamp_utc' => now(),
            ]);
        }
    }

    /**
     * Actualiza el estado de notificación del evento.
     */
    private function updateEventNotificationStatus(array $results): void
    {
        $hasSuccess = collect($results)->contains('success', true);
        
        // Buscar el primer call_sid exitoso (para callbacks)
        $callSid = null;
        foreach ($results as $result) {
            if ($result['channel'] === 'call' && $result['success'] && !empty($result['call_sid'])) {
                $callSid = $result['call_sid'];
                break;
            }
        }

        $updateData = [
            'notification_status' => $hasSuccess ? 'sent' : 'failed',
            'notification_sent_at' => $hasSuccess ? now() : null,
            'notification_channels' => collect($results)
                ->where('success', true)
                ->pluck('channel')
                ->unique()
                ->values()
                ->toArray(),
        ];

        if ($callSid) {
            $updateData['twilio_call_sid'] = $callSid;
        }

        $this->event->update($updateData);

        if ($callSid) {
            Log::info('SendNotificationJob: twilio_call_sid guardado para callbacks', [
                'event_id' => $this->event->id,
                'call_sid' => $callSid,
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendNotificationJob: Falló permanentemente', [
            'event_id' => $this->event->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Actualizar estado del evento
        $this->event->update([
            'notification_status' => 'failed',
        ]);
    }
}
