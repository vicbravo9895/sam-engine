<?php

namespace App\Jobs;

use App\Jobs\Traits\PersistsEvidenceImages;
use App\Models\SamsaraEvent;
use App\Services\ContactResolver;
use App\Services\SamsaraClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Job para revalidar eventos de Samsara que requieren monitoreo continuo.
 * 
 * ACTUALIZADO: Nuevo contrato de respuesta del AI Service.
 */
class RevalidateSamsaraEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, PersistsEvidenceImages;

    /**
     * Número de intentos antes de fallar
     */
    public $tries = 2;

    /**
     * Timeout en segundos (5 minutos)
     */
    public $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public SamsaraEvent $event
    ) {
        $this->onQueue('samsara-revalidation');
    }

    /**
     * Execute the job.
     */
    public function handle(ContactResolver $contactResolver): void
    {
        // Verificar que el evento aún esté en investigating
        if ($this->event->ai_status !== SamsaraEvent::STATUS_INVESTIGATING) {
            Log::info("Event no longer investigating, skipping revalidation", [
                'event_id' => $this->event->id,
                'current_status' => $this->event->ai_status,
            ]);
            return;
        }

        // Verificar límite de investigaciones
        if ($this->event->investigation_count >= SamsaraEvent::getMaxInvestigations()) {
            $this->event->markAsCompleted(
                assessment: array_merge($this->event->ai_assessment ?? [], [
                    'verdict' => 'needs_review',
                    'likelihood' => 'medium',
                    'confidence' => 0.5,
                    'reasoning' => 'Máximo de revalidaciones alcanzado. Requiere revisión humana.',
                    'risk_escalation' => 'warn',
                    'requires_monitoring' => false,
                ]),
                humanMessage: 'Este evento requiere revisión manual después de ' . SamsaraEvent::getMaxInvestigations() . ' análisis automáticos.',
                alertContext: $this->event->alert_context,
                notificationDecision: $this->event->notification_decision,
                notificationExecution: null,
                execution: $this->event->ai_actions
            );

            Log::warning("Event reached max investigations", [
                'event_id' => $this->event->id,
                'investigation_count' => $this->event->investigation_count,
            ]);
            return;
        }

        Log::info("Revalidating event", [
            'event_id' => $this->event->id,
            'investigation_count' => $this->event->investigation_count,
        ]);

        try {
            // Resolver contactos para notificaciones
            $contacts = $contactResolver->resolveForEvent($this->event);
            $contactPayload = $contactResolver->formatForPayload($contacts);

            // Enriquecer el payload con los contactos
            $enrichedPayload = array_merge($this->event->raw_payload, $contactPayload);

            // =========================================================
            // RECARGAR DATOS DE SAMSARA CON VENTANA TEMPORAL ACTUALIZADA
            // =========================================================
            // Esto es CRÍTICO para revalidaciones: debemos buscar información
            // NUEVA desde la última investigación hasta ahora, no repetir
            // la misma ventana temporal del evento original.
            $enrichedPayload = $this->reloadSamsaraDataForRevalidation($enrichedPayload);

            $aiServiceUrl = config('services.ai_engine.url');

            // Timestamp actual para el contexto de revalidación
            $currentRevalidationTime = now()->toIso8601String();

            // Construir contexto de revalidación
            $revalidationContext = [
                'is_revalidation' => true,
                'original_event_time' => $this->event->occurred_at?->toIso8601String(),
                'first_investigation_time' => $this->event->created_at->toIso8601String(),
                'last_investigation_time' => $this->event->last_investigation_at?->toIso8601String(),
                'current_revalidation_time' => $currentRevalidationTime,
                'investigation_count' => $this->event->investigation_count,
                'previous_assessment' => $this->event->ai_assessment,
                'previous_alert_context' => $this->event->alert_context,
                'investigation_history' => $this->event->investigation_history ?? [],
            ];

            $response = Http::timeout(120)
                ->post("{$aiServiceUrl}/alerts/revalidate", [
                    'event_id' => $this->event->id,
                    'payload' => $enrichedPayload,
                    'context' => $revalidationContext,
                ]);

            // Manejar 503 (Service at Capacity) - el AI Service está sobrecargado
            // Laravel reintentará automáticamente después del backoff
            if ($response->status() === 503) {
                $stats = $response->json('stats', []);
                Log::warning("AI service at capacity during revalidation, will retry", [
                    'event_id' => $this->event->id,
                    'attempt' => $this->attempts(),
                    'ai_stats' => $stats,
                ]);
                $activeRequests = $stats['active_requests'] ?? '?';
                $pendingRequests = $stats['pending_requests'] ?? '?';
                throw new \Exception("AI service at capacity. Active: {$activeRequests}, Pending: {$pendingRequests}");
            }

            if ($response->failed()) {
                throw new \Exception("AI service returned error: " . $response->body());
            }

            $result = $response->json();

            Log::info("Revalidation response received", [
                'event_id' => $this->event->id,
                'status' => $result['status'] ?? 'unknown',
                'has_assessment' => isset($result['assessment']),
                'has_human_message' => isset($result['human_message']),
            ]);

            // Extraer datos del nuevo contrato
            $alertContext = $result['alert_context'] ?? $this->event->alert_context;
            $assessment = $result['assessment'] ?? [];
            $humanMessage = $result['human_message'] ?? 'Revalidación completada';
            $notificationDecision = $result['notification_decision'] ?? null;
            $notificationExecution = $result['notification_execution'] ?? null;
            $execution = $result['execution'] ?? $this->event->ai_actions;
            $cameraAnalysis = $result['camera_analysis'] ?? null;

            // Persistir imágenes de evidencia si existen
            [$execution, $cameraAnalysis] = $this->persistEvidenceImages($execution, $cameraAnalysis);

            // Agregar camera_analysis al execution para que se guarde en ai_actions
            if ($cameraAnalysis) {
                $execution['camera_analysis'] = $cameraAnalysis;
            }

            // Extraer información de monitoreo desde el assessment
            $requiresMonitoring = $assessment['requires_monitoring'] ?? false;
            $nextCheckMinutes = $assessment['next_check_minutes'] ?? 30;
            $monitoringReason = $assessment['monitoring_reason'] ?? null;

            // Verificar si aún requiere monitoreo
            if ($requiresMonitoring) {
                $this->event->markAsInvestigating(
                    assessment: $assessment,
                    humanMessage: $humanMessage,
                    nextCheckMinutes: $nextCheckMinutes,
                    alertContext: $alertContext,
                    notificationDecision: $notificationDecision,
                    notificationExecution: $notificationExecution,
                    execution: $execution
                );

                $this->event->addInvestigationRecord(
                    reason: $monitoringReason ?? 'Requiere más tiempo para contexto'
                );

                // Guardar twilio_call_sid si hubo llamada exitosa (para callbacks)
                $this->persistTwilioCallSid($notificationExecution);

                // Programar siguiente revalidación
                self::dispatch($this->event)
                    ->delay(now()->addMinutes($nextCheckMinutes))
                    ->onQueue('samsara-revalidation');

                Log::info("Event continues under investigation", [
                    'event_id' => $this->event->id,
                    'next_check_minutes' => $nextCheckMinutes,
                    'investigation_count' => $this->event->investigation_count,
                    'verdict' => $assessment['verdict'] ?? 'unknown',
                    'risk_escalation' => $assessment['risk_escalation'] ?? 'unknown',
                ]);
            } else {
                // La AI ahora está segura - completar
                $this->event->markAsCompleted(
                    assessment: $assessment,
                    humanMessage: $humanMessage,
                    alertContext: $alertContext,
                    notificationDecision: $notificationDecision,
                    notificationExecution: $notificationExecution,
                    execution: $execution
                );

                // Guardar twilio_call_sid si hubo llamada exitosa (para callbacks)
                $this->persistTwilioCallSid($notificationExecution);

                Log::info("Event investigation completed", [
                    'event_id' => $this->event->id,
                    'final_verdict' => $assessment['verdict'] ?? 'unknown',
                    'risk_escalation' => $assessment['risk_escalation'] ?? 'unknown',
                    'total_investigations' => $this->event->investigation_count,
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Failed to revalidate event", [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // En caso de error, programar reintento
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Revalidation job failed permanently", [
            'event_id' => $this->event->id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Persiste la información de la ventana de tiempo consultada en el historial.
     * 
     * Esto permite que cada revalidación tenga un registro de:
     * - Qué período de tiempo se consultó
     * - Qué datos se encontraron (resumen)
     * - Cuándo se hizo la consulta
     * 
     * El AI puede usar este historial para ver la "evolución" de la situación.
     * 
     * @param array $reloadedData Los datos recargados de Samsara
     */
    private function persistRevalidationWindow(array $reloadedData): void
    {
        $metadata = $reloadedData['_metadata'] ?? [];
        $queryWindow = $metadata['query_window'] ?? [];
        
        // Construir resumen de lo que se encontró en esta ventana
        $windowSummary = [
            'investigation_number' => $this->event->investigation_count + 1,
            'queried_at' => now()->toIso8601String(),
            'time_window' => [
                'start' => $queryWindow['start'] ?? null,
                'end' => $queryWindow['end'] ?? null,
                'minutes_covered' => $queryWindow['minutes_covered'] ?? 0,
            ],
            'findings' => [
                'new_safety_events' => $reloadedData['safety_events_since_last_check']['total_events'] ?? 0,
                'new_camera_items' => $reloadedData['camera_media_since_last_check']['total_items'] ?? 0,
                'has_vehicle_stats' => !empty($reloadedData['vehicle_stats_since_last_check']),
            ],
            // Resumen de safety events encontrados (si los hay)
            'safety_events_details' => $this->summarizeSafetyEvents(
                $reloadedData['safety_events_since_last_check']['events'] ?? []
            ),
        ];
        
        // Agregar al historial existente
        $history = $this->event->investigation_history ?? [];
        $history[] = $windowSummary;
        
        // Actualizar el modelo (sin disparar el evento completo de markAsInvestigating)
        $this->event->update(['investigation_history' => $history]);
        
        Log::debug('RevalidateSamsaraEventJob: Window persisted to investigation_history', [
            'event_id' => $this->event->id,
            'investigation_number' => $windowSummary['investigation_number'],
            'window_start' => $windowSummary['time_window']['start'],
            'window_end' => $windowSummary['time_window']['end'],
        ]);
    }
    
    /**
     * Resume los safety events encontrados para el historial.
     * 
     * @param array $events Lista de eventos de seguridad
     * @return array Resumen de los eventos
     */
    private function summarizeSafetyEvents(array $events): array
    {
        if (empty($events)) {
            return [];
        }
        
        return array_map(function ($event) {
            return [
                'behavior' => $event['behavior_label'] ?? 'unknown',
                'severity' => $event['severity'] ?? 'unknown',
                'time' => $event['time'] ?? null,
            ];
        }, array_slice($events, 0, 5)); // Máximo 5 eventos para no sobrecargar
    }

    /**
     * Recarga datos de Samsara con una ventana temporal ACTUALIZADA.
     * 
     * En revalidaciones, necesitamos buscar información NUEVA:
     * - Desde la última investigación hasta ahora
     * - No repetir la misma ventana del evento original
     * 
     * Esto permite detectar cambios en la situación del vehículo
     * y tomar decisiones basadas en información fresca.
     * 
     * @param array $payload El payload original del webhook
     * @return array El payload enriquecido con datos actualizados
     */
    private function reloadSamsaraDataForRevalidation(array $payload): array
    {
        // Obtener la API key de la empresa del evento (multi-tenant)
        $company = $this->event->company;
        if (!$company) {
            Log::warning('RevalidateSamsaraEventJob: No company found, skipping Samsara reload', [
                'event_id' => $this->event->id,
            ]);
            return $payload;
        }

        $samsaraApiKey = $company->getSamsaraApiKey();
        if (empty($samsaraApiKey)) {
            Log::warning('RevalidateSamsaraEventJob: No Samsara API key, skipping reload', [
                'event_id' => $this->event->id,
                'company_id' => $company->id,
            ]);
            return $payload;
        }

        // Extraer contexto del evento
        $context = SamsaraClient::extractEventContext($payload);
        if (!$context) {
            Log::warning('RevalidateSamsaraEventJob: Could not extract event context, skipping reload', [
                'event_id' => $this->event->id,
            ]);
            return $payload;
        }

        $vehicleId = $context['vehicle_id'];
        $originalEventTime = $context['happened_at_time'];
        $lastInvestigationTime = $this->event->last_investigation_at?->toIso8601String() 
            ?? $this->event->created_at->toIso8601String();
        $isSafetyEvent = SamsaraClient::isSafetyEvent($payload);

        Log::info('RevalidateSamsaraEventJob: Reloading Samsara data with updated time window', [
            'event_id' => $this->event->id,
            'vehicle_id' => $vehicleId,
            'original_event_time' => $originalEventTime,
            'last_investigation_time' => $lastInvestigationTime,
            'is_safety_event' => $isSafetyEvent,
            'investigation_count' => $this->event->investigation_count,
        ]);

        try {
            // Crear cliente Samsara y recargar datos
            $samsaraClient = new SamsaraClient($samsaraApiKey);
            $reloadedData = $samsaraClient->reloadDataForRevalidation(
                vehicleId: $vehicleId,
                originalEventTime: $originalEventTime,
                lastInvestigationTime: $lastInvestigationTime,
                isSafetyEvent: $isSafetyEvent
            );

            if (empty($reloadedData)) {
                Log::warning('RevalidateSamsaraEventJob: No data reloaded from Samsara', [
                    'event_id' => $this->event->id,
                ]);
                return $payload;
            }

            // Agregar datos recargados al payload bajo una key especial
            // Mantenemos los preloaded_data originales para referencia
            $payload['revalidation_data'] = $reloadedData;

            // Actualizar vehicle_info y driver_assignment con los datos frescos
            // (estos pueden haber cambiado)
            if (!empty($reloadedData['vehicle_info'])) {
                $payload['preloaded_data']['vehicle_info'] = $reloadedData['vehicle_info'];
            }
            if (!empty($reloadedData['driver_assignment'])) {
                $payload['preloaded_data']['driver_assignment'] = $reloadedData['driver_assignment'];
            }

            // =========================================================
            // PERSISTIR HISTORIAL DE VENTANAS CONSULTADAS
            // =========================================================
            // Esto permite que el AI vea el "progreso" de todas las ventanas
            // que ya se han revisado, no solo la actual.
            $this->persistRevalidationWindow($reloadedData);

            // Incluir el historial acumulado de ventanas en el payload
            // para que el AI tenga contexto completo
            $payload['revalidation_windows_history'] = $this->event->fresh()->investigation_history ?? [];

            Log::info('RevalidateSamsaraEventJob: Samsara data reloaded successfully', [
                'event_id' => $this->event->id,
                'has_new_vehicle_stats' => !empty($reloadedData['vehicle_stats_since_last_check']),
                'new_safety_events_count' => $reloadedData['safety_events_since_last_check']['total_events'] ?? 0,
                'new_camera_items_count' => $reloadedData['camera_media_since_last_check']['total_items'] ?? 0,
                'query_window_minutes' => $reloadedData['_metadata']['query_window']['minutes_covered'] ?? null,
            ]);

            return $payload;

        } catch (\Exception $e) {
            Log::error('RevalidateSamsaraEventJob: Failed to reload Samsara data', [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);
            return $payload;
        }
    }

    /**
     * Guarda el twilio_call_sid del primer call exitoso para que el
     * TwilioCallbackController pueda encontrar el evento por CallSid.
     * 
     * @param array|null $notificationExecution Resultados de ejecución de notificaciones
     */
    private function persistTwilioCallSid(?array $notificationExecution): void
    {
        if (!$notificationExecution || !($notificationExecution['attempted'] ?? false)) {
            return;
        }

        $results = $notificationExecution['results'] ?? [];
        
        foreach ($results as $result) {
            // Buscar el primer call exitoso con call_sid
            if (
                ($result['channel'] ?? '') === 'call' &&
                ($result['success'] ?? false) &&
                !empty($result['call_sid'])
            ) {
                $this->event->update([
                    'twilio_call_sid' => $result['call_sid'],
                    'notification_status' => 'sent',
                    'notification_sent_at' => now(),
                ]);

                Log::debug("Twilio call_sid persisted for callbacks (revalidation)", [
                    'event_id' => $this->event->id,
                    'call_sid' => $result['call_sid'],
                ]);

                break; // Solo guardamos el primer call_sid
            }
        }
    }
}

