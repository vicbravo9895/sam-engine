<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\NotificationResult;
use App\Models\SamsaraEvent;
use App\Models\SamsaraEventActivity;
use App\Services\TwilioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job para escalar notificaciones cuando un operador CONFIRMA un botón de pánico.
 * 
 * Se notifica a:
 * - Equipo de Monitoreo (monitoring_team) → WhatsApp + SMS
 * - Contactos de Emergencia (emergency) → Llamada + WhatsApp + SMS
 */
class SendPanicEscalationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;
    public $backoff = [10, 30, 60];

    public function __construct(
        public SamsaraEvent $event
    ) {
        $this->onQueue('notifications');
    }

    public function handle(TwilioService $twilioService): void
    {
        $startTime = microtime(true);
        
        Log::channel('stack')->info('========================================');
        Log::channel('stack')->info('=== PANIC ESCALATION JOB STARTED ===');
        Log::channel('stack')->info('========================================', [
            'event_id' => $this->event->id,
            'vehicle_name' => $this->event->vehicle_name,
            'driver_name' => $this->event->driver_name,
            'company_id' => $this->event->company_id,
        ]);

        $results = [];
        $companyId = $this->event->company_id;

        // Preparar datos para templates y mensajes
        $templateVars = $this->buildTemplateVariables();
        $smsMessage = $this->buildSmsMessage();
        $callScript = $this->buildCallScript();

        Log::info('Panic escalation prepared', [
            'event_id' => $this->event->id,
            'template_vars' => $templateVars,
            'sms_preview' => mb_substr($smsMessage, 0, 100),
        ]);

        // 1. Notificar al Equipo de Monitoreo (WhatsApp Template + SMS)
        $monitoringContacts = Contact::where('company_id', $companyId)
            ->where('type', Contact::TYPE_MONITORING_TEAM)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get();

        Log::info('Monitoring contacts found', [
            'event_id' => $this->event->id,
            'count' => $monitoringContacts->count(),
            'contacts' => $monitoringContacts->pluck('name')->toArray(),
        ]);

        foreach ($monitoringContacts as $contact) {
            // WhatsApp con Template (permite iniciar conversación)
            if ($contact->whatsapp_number) {
                $result = $twilioService->sendWhatsappTemplate(
                    to: $contact->whatsapp_number,
                    templateSid: TwilioService::TEMPLATE_ESCALATION_MONITORING,
                    variables: $templateVars
                );
                $results[] = $this->recordResult('whatsapp_template', 'monitoring_team', $contact, $result);
                
                Log::info('Monitoring WhatsApp Template sent', [
                    'event_id' => $this->event->id,
                    'contact' => $contact->name,
                    'to' => $contact->whatsapp_number,
                    'template' => 'sam_escalation_monitoring',
                    'success' => $result['success'] ?? false,
                ]);
            }

            // SMS (fallback)
            if ($contact->phone) {
                $result = $twilioService->sendSms($contact->phone, $smsMessage);
                $results[] = $this->recordResult('sms', 'monitoring_team', $contact, $result);
                
                Log::info('Monitoring SMS sent', [
                    'event_id' => $this->event->id,
                    'contact' => $contact->name,
                    'to' => $contact->phone,
                    'success' => $result['success'] ?? false,
                ]);
            }
        }

        // 2. Notificar a Emergencia (Llamada + WhatsApp Template + SMS)
        $emergencyContacts = Contact::where('company_id', $companyId)
            ->where('type', Contact::TYPE_EMERGENCY)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get();

        Log::info('Emergency contacts found', [
            'event_id' => $this->event->id,
            'count' => $emergencyContacts->count(),
            'contacts' => $emergencyContacts->pluck('name')->toArray(),
        ]);

        foreach ($emergencyContacts as $contact) {
            // Llamada de emergencia (prioridad máxima)
            if ($contact->phone) {
                $result = $twilioService->makeCall($contact->phone, $callScript);
                $results[] = $this->recordResult('call', 'emergency', $contact, $result);
                
                Log::info('Emergency call initiated', [
                    'event_id' => $this->event->id,
                    'contact' => $contact->name,
                    'to' => $contact->phone,
                    'success' => $result['success'] ?? false,
                ]);
            }

            // WhatsApp con Template
            if ($contact->whatsapp_number) {
                $result = $twilioService->sendWhatsappTemplate(
                    to: $contact->whatsapp_number,
                    templateSid: TwilioService::TEMPLATE_PANIC_CONFIRMED,
                    variables: $templateVars
                );
                $results[] = $this->recordResult('whatsapp_template', 'emergency', $contact, $result);
            }

            // SMS
            if ($contact->phone) {
                $result = $twilioService->sendSms($contact->phone, $smsMessage);
                $results[] = $this->recordResult('sms', 'emergency', $contact, $result);
            }
        }

        // 3. Notificar a Supervisores también (WhatsApp Template + SMS)
        $supervisorContacts = Contact::where('company_id', $companyId)
            ->where('type', Contact::TYPE_SUPERVISOR)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get();

        Log::info('Supervisor contacts found', [
            'event_id' => $this->event->id,
            'count' => $supervisorContacts->count(),
        ]);

        foreach ($supervisorContacts as $contact) {
            if ($contact->whatsapp_number) {
                $result = $twilioService->sendWhatsappTemplate(
                    to: $contact->whatsapp_number,
                    templateSid: TwilioService::TEMPLATE_ESCALATION_MONITORING,
                    variables: $templateVars
                );
                $results[] = $this->recordResult('whatsapp_template', 'supervisor', $contact, $result);
            }
            if ($contact->phone) {
                $result = $twilioService->sendSms($contact->phone, $smsMessage);
                $results[] = $this->recordResult('sms', 'supervisor', $contact, $result);
            }
        }

        // Persistir resultados
        $this->persistResults($results);

        // Actualizar evento
        $successCount = collect($results)->where('success', true)->count();
        $failedCount = collect($results)->where('success', false)->count();
        
        $channels = collect($results)
            ->where('success', true)
            ->pluck('channel')
            ->unique()
            ->values()
            ->toArray();

        $this->event->update([
            'notification_status' => 'escalated',
            'notification_channels' => $channels,
            'notification_sent_at' => now(),
        ]);

        // Registrar actividad
        try {
            SamsaraEventActivity::create([
                'samsara_event_id' => $this->event->id,
                'company_id' => $this->event->company_id,
                'action' => 'panic_escalation_sent',
                'data' => [
                    'total_notifications' => count($results),
                    'successful' => $successCount,
                    'failed' => $failedCount,
                    'channels' => $channels,
                    'monitoring_contacts' => $monitoringContacts->count(),
                    'emergency_contacts' => $emergencyContacts->count(),
                    'supervisor_contacts' => $supervisorContacts->count(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log escalation activity', ['error' => $e->getMessage()]);
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        Log::channel('stack')->info('========================================');
        Log::channel('stack')->info('=== PANIC ESCALATION JOB COMPLETED ===');
        Log::channel('stack')->info('========================================', [
            'event_id' => $this->event->id,
            'total_notifications' => count($results),
            'successful' => $successCount,
            'failed' => $failedCount,
            'channels_used' => $channels,
            'duration_ms' => $duration,
        ]);
    }

    /**
     * Construye las variables para los WhatsApp Templates.
     * 
     * Variables para sam_escalation_monitoring / sam_panic_confirmed:
     * {{1}} = Nombre del vehículo
     * {{2}} = Nombre del conductor  
     * {{3}} = Tipo de alerta
     * {{4}} = Ubicación (si está disponible)
     * {{5}} = Mensaje adicional o instrucciones
     * 
     * @return array Variables indexadas por número ['1' => 'valor', '2' => 'valor', ...]
     */
    private function buildTemplateVariables(): array
    {
        $event = $this->event;
        $timezone = $event->company?->timezone ?? 'America/Mexico_City';
        $occurredAt = $event->occurred_at?->setTimezone($timezone)->format('d/m/Y H:i') ?? 'N/A';
        
        // Intentar obtener ubicación del payload
        $location = $this->extractLocation();

        return [
            '1' => $event->vehicle_name ?? 'Unidad desconocida',
            '2' => $event->driver_name ?? 'Conductor no identificado',
            '3' => 'Botón de Pánico - CONFIRMADO',
            '4' => $location,
            '5' => "Hora: {$occurredAt}. Acción inmediata requerida.",
        ];
    }

    /**
     * Extrae la ubicación del payload del evento si está disponible.
     */
    private function extractLocation(): string
    {
        $payload = $this->event->raw_payload ?? [];
        
        // Intentar obtener dirección formateada
        if (isset($payload['data']['location']['formattedAddress'])) {
            return $payload['data']['location']['formattedAddress'];
        }
        
        // Si hay coordenadas, formatearlas
        if (isset($payload['data']['location']['latitude'], $payload['data']['location']['longitude'])) {
            $lat = round($payload['data']['location']['latitude'], 6);
            $lng = round($payload['data']['location']['longitude'], 6);
            return "Coords: {$lat}, {$lng}";
        }
        
        return 'Ubicación no disponible';
    }

    /**
     * Construye el mensaje para SMS (fallback, no usa templates).
     */
    private function buildSmsMessage(): string
    {
        $event = $this->event;
        $timezone = $event->company?->timezone ?? 'America/Mexico_City';
        $occurredAt = $event->occurred_at?->setTimezone($timezone)->format('H:i') ?? 'N/A';

        return "🚨 EMERGENCIA CONFIRMADA\n" .
            "Unidad: {$event->vehicle_name}\n" .
            "Conductor: " . ($event->driver_name ?? 'No identificado') . "\n" .
            "Hora: {$occurredAt}\n" .
            "ACCIÓN INMEDIATA REQUERIDA";
    }

    /**
     * Construye el script para llamadas de emergencia (TTS).
     */
    private function buildCallScript(): string
    {
        $event = $this->event;
        
        return "Alerta de emergencia. El operador de la unidad {$event->vehicle_name} " .
            "ha confirmado un botón de pánico real. " .
            "Conductor: " . ($event->driver_name ?? 'no identificado') . ". " .
            "Se requiere acción inmediata. Contacte al conductor.";
    }

    /**
     * Registra el resultado de una notificación.
     */
    private function recordResult(string $channel, string $recipientType, Contact $contact, array $result): array
    {
        return [
            'channel' => $channel,
            'recipient_type' => $recipientType,
            'contact_name' => $contact->name,
            'to' => $result['to'] ?? $contact->phone,
            'success' => $result['success'] ?? false,
            'error' => $result['error'] ?? null,
            'sid' => $result['sid'] ?? null,
        ];
    }

    /**
     * Persiste los resultados en la BD.
     */
    private function persistResults(array $results): void
    {
        foreach ($results as $result) {
            try {
                NotificationResult::create([
                    'samsara_event_id' => $this->event->id,
                    'channel' => $result['channel'],
                    'recipient_type' => $result['recipient_type'],
                    'to_number' => $result['to'],
                    'success' => $result['success'],
                    'error' => $result['error'],
                    'call_sid' => $result['channel'] === 'call' ? $result['sid'] : null,
                    'message_sid' => $result['channel'] !== 'call' ? $result['sid'] : null,
                    'timestamp_utc' => now(),
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to persist notification result', [
                    'error' => $e->getMessage(),
                    'result' => $result,
                ]);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendPanicEscalationJob failed', [
            'event_id' => $this->event->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
