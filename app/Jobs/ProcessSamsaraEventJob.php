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
 * Job para procesar eventos de Samsara.
 * 
 * ACTUALIZADO: Nuevo contrato de respuesta del AI Service.
 * - alert_context: JSON estructurado del triage
 * - assessment: Evaluación técnica
 * - human_message: Mensaje para humanos (STRING)
 * - notification_decision: Decisión sin side effects
 * - notification_execution: Resultados de ejecución
 * - execution: Trazabilidad
 */
class ProcessSamsaraEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, PersistsEvidenceImages;
    use Traits\LogsWithTenantContext;

    /**
     * Número de intentos antes de fallar
     */
    public $tries = 3;

    /**
     * Timeout en segundos (5 minutos)
     */
    public $timeout = 300;

    /**
     * Tiempo de espera entre reintentos (en segundos)
     */
    public $backoff = [30, 60, 120];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public SamsaraEvent $event
    ) {
        // El job se ejecutará en la cola 'samsara-events'
        $this->onQueue('samsara-events');
    }

    /**
     * Execute the job.
     */
    public function handle(ContactResolver $contactResolver): void
    {
        // Refrescar el evento desde la BD para obtener el estado más reciente
        $this->event->refresh();
        
        // Registrar contexto de empresa para todos los logs de este job
        $this->setLogContext($this->event->company);
        
        $jobStartTime = microtime(true);
        $traceId = $this->getTraceId();

        Log::info("Job started: ProcessSamsaraEvent", [
            'trace_id' => $traceId,
            'event_id' => $this->event->id,
            'samsara_event_id' => $this->event->samsara_event_id,
            'event_type' => $this->event->event_type,
            'event_description' => $this->event->event_description,
            'vehicle_name' => $this->event->vehicle_name,
            'severity' => $this->event->severity,
            'current_status' => $this->event->ai_status,
            'job_attempt' => $this->attempts(),
            'max_attempts' => $this->tries,
        ]);

        // Protección contra procesamiento duplicado
        if (in_array($this->event->ai_status, [
            SamsaraEvent::STATUS_COMPLETED,
            SamsaraEvent::STATUS_INVESTIGATING,
        ])) {
            Log::info("Job skipped: Event already processed", [
                'trace_id' => $traceId,
                'event_id' => $this->event->id,
                'current_status' => $this->event->ai_status,
                'reason' => 'duplicate_processing',
            ]);
            return;
        }

        // Si está en processing pero no es un reintento, podría ser un job duplicado
        if ($this->event->ai_status === SamsaraEvent::STATUS_PROCESSING && $this->attempts() === 1) {
            Log::warning("Job warning: Possible duplicate job", [
                'trace_id' => $traceId,
                'event_id' => $this->event->id,
                'attempt' => $this->attempts(),
            ]);
        }

        try {
            // Marcar como procesando
            $this->event->markAsProcessing();

            // Obtener la API key de la empresa del evento (multi-tenant)
            $company = $this->event->company;
            if (!$company) {
                throw new \Exception("Event has no company associated. Cannot process without API key.");
            }

            $samsaraApiKey = $company->getSamsaraApiKey();
            if (empty($samsaraApiKey)) {
                throw new \Exception("Company {$company->id} ({$company->name}) does not have Samsara API key configured.");
            }

            // Crear cliente Samsara con la API key de la empresa
            $samsaraClient = new SamsaraClient($samsaraApiKey);

            Log::info("Using company Samsara API key", [
                'event_id' => $this->event->id,
                'company_id' => $company->id,
                'company_name' => $company->name,
                'has_api_key' => !empty($samsaraApiKey),
            ]);

            // Resolver contactos para notificaciones
            $contacts = $contactResolver->resolveForEvent($this->event);
            $contactPayload = $contactResolver->formatForPayload($contacts);

            Log::info("Contacts resolved for event", [
                'event_id' => $this->event->id,
                'contacts_types' => array_keys($contacts),
                'has_operator' => isset($contacts['operator']),
                'has_monitoring_team' => isset($contacts['monitoring_team']),
            ]);

            // Enriquecer el payload con los contactos
            $enrichedPayload = array_merge($this->event->raw_payload, $contactPayload);
            
            // Pre-cargar TODA la información de Samsara en paralelo
            // Esto ahorra ~4-5 segundos al evitar que el AI llame a las tools
            $enrichedPayload = $this->preloadSamsaraData($enrichedPayload, $samsaraClient);

            // Actualizar la descripción del evento si se encontró un behavior_name más específico
            $this->updateEventDescriptionFromSafetyEvent($enrichedPayload);

            Log::info('Enriched payload ready', [
                'event_id' => $this->event->id,
                'has_preloaded_data' => isset($enrichedPayload['preloaded_data']),
                'preload_duration_ms' => $enrichedPayload['preloaded_data']['_metadata']['duration_ms'] ?? null,
            ]);

            // Llamar al servicio de IA (FastAPI)
            $aiServiceUrl = config('services.ai_engine.url');

            // Agregar company_id al payload para que el AI Service pueda extraerlo
            $enrichedPayload['company_id'] = $company->id;

            $response = Http::timeout(300)
                ->withHeaders([
                    'X-Trace-ID' => $traceId, // Propagar trace_id para trazabilidad distribuida
                ])
                ->post("{$aiServiceUrl}/alerts/ingest", [
                    'event_id' => $this->event->id,
                    'payload' => $enrichedPayload,
                ]);

            // Manejar 503 (Service at Capacity) - el AI Service está sobrecargado
            // Laravel reintentará automáticamente después del backoff
            if ($response->status() === 503) {
                $stats = $response->json('stats', []);
                Log::warning("AI service at capacity, will retry", [
                    'event_id' => $this->event->id,
                    'attempt' => $this->attempts(),
                    'ai_stats' => $stats,
                ]);
                $activeRequests = $stats['active_requests'] ?? '?';
                $pendingRequests = $stats['pending_requests'] ?? '?';
                throw new \Exception("AI service at capacity. Active: {$activeRequests}, Pending: {$pendingRequests}");
            }

            if ($response->failed()) {
                throw new \Exception("AI service returned HTTP error: " . $response->body());
            }

            $result = $response->json();

            // Log detallado del response para debugging
            Log::info("AI service response received", [
                'event_id' => $this->event->id,
                'status' => $result['status'] ?? 'unknown',
                'has_alert_context' => isset($result['alert_context']),
                'has_assessment' => isset($result['assessment']),
                'has_human_message' => isset($result['human_message']),
                'has_notification_decision' => isset($result['notification_decision']),
                'has_notification_execution' => isset($result['notification_execution']),
                'has_execution' => isset($result['execution']),
            ]);

            // =========================================================
            // VALIDACIÓN CRÍTICA: Verificar status del JSON
            // El AI Service puede devolver HTTP 200 pero con status "error"
            // si el pipeline falló internamente
            // =========================================================
            if (($result['status'] ?? 'unknown') === 'error') {
                $errorMessage = $result['error'] ?? 'Unknown pipeline error';
                Log::error("AI Service returned error status in JSON", [
                    'event_id' => $this->event->id,
                    'error' => $errorMessage,
                    'result_keys' => array_keys($result),
                ]);
                throw new \Exception("AI service pipeline error: {$errorMessage}");
            }

            // Validar que tenemos un assessment válido con los campos requeridos
            $assessment = $result['assessment'] ?? [];
            if (empty($assessment) || !isset($assessment['verdict'])) {
                Log::error("AI Service returned empty or invalid assessment", [
                    'event_id' => $this->event->id,
                    'has_assessment' => !empty($assessment),
                    'has_verdict' => isset($assessment['verdict']),
                    'assessment_keys' => array_keys($assessment),
                ]);
                throw new \Exception("AI service returned invalid assessment: missing verdict");
            }

            // Extraer datos del nuevo contrato
            $alertContext = $result['alert_context'] ?? null;
            // $assessment ya fue validado arriba
            $humanMessage = $result['human_message'] ?? 'Procesamiento completado';
            $notificationDecision = $result['notification_decision'] ?? null;
            $notificationExecution = $result['notification_execution'] ?? null;
            $execution = $result['execution'] ?? null;
            $cameraAnalysis = $result['camera_analysis'] ?? null;
            
            // Persistir imágenes de evidencia si existen
            // Esto descarga las imágenes de Samsara y las guarda localmente
            [$execution, $cameraAnalysis] = $this->persistEvidenceImages($execution, $cameraAnalysis);

            // Agregar camera_analysis al execution para que se guarde en ai_actions
            if ($cameraAnalysis) {
                $execution['camera_analysis'] = $cameraAnalysis;
            }

            // Extraer información de monitoreo desde el assessment
            $requiresMonitoring = $assessment['requires_monitoring'] ?? false;
            $nextCheckMinutes = $assessment['next_check_minutes'] ?? 15;
            $monitoringReason = $assessment['monitoring_reason'] ?? null;

            // Verificar si la AI requiere monitoreo continuo
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
                    reason: $monitoringReason ?? 'Confianza insuficiente para veredicto final'
                );

                // Guardar twilio_call_sid si hubo llamada exitosa (para callbacks)
                $this->persistTwilioCallSid($notificationExecution);

                // Programar revalidación
                $scheduledAt = now()->addMinutes($nextCheckMinutes);
                
                RevalidateSamsaraEventJob::dispatch($this->event)
                    ->delay($scheduledAt)
                    ->onQueue('samsara-revalidation');

                $jobDuration = round((microtime(true) - $jobStartTime) * 1000, 2);

                // Log al canal de revalidation para tracking completo
                $revalidationLogContext = [
                    'trace_id' => $traceId,
                    'event_id' => $this->event->id,
                    'samsara_event_id' => $this->event->samsara_event_id,
                    'vehicle_name' => $this->event->vehicle_name,
                    'next_check_minutes' => $nextCheckMinutes,
                    'scheduled_at' => $scheduledAt->toIso8601String(),
                    'queue' => 'samsara-revalidation',
                    'verdict' => $assessment['verdict'] ?? 'unknown',
                    'risk_escalation' => $assessment['risk_escalation'] ?? 'unknown',
                    'monitoring_reason' => $monitoringReason,
                    'proactive_flag' => $alertContext['proactive_flag'] ?? false,
                    'duration_ms' => $jobDuration,
                ];
                
                Log::channel('revalidation')->info('[REVALIDATION] ========== FIRST REVALIDATION SCHEDULED ==========', $revalidationLogContext);

                Log::info("Job completed: ProcessSamsaraEvent (monitoring)", [
                    'trace_id' => $traceId,
                    'event_id' => $this->event->id,
                    'samsara_event_id' => $this->event->samsara_event_id,
                    'vehicle_name' => $this->event->vehicle_name,
                    'final_status' => 'investigating',
                    'next_check_minutes' => $nextCheckMinutes,
                    'verdict' => $assessment['verdict'] ?? 'unknown',
                    'risk_escalation' => $assessment['risk_escalation'] ?? 'unknown',
                    'proactive_flag' => $alertContext['proactive_flag'] ?? false,
                    'duration_ms' => $jobDuration,
                ]);
            } else {
                // Flujo normal - completar
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
                
                // Check for correlations with other events
                $this->checkCorrelations();

                $jobDuration = round((microtime(true) - $jobStartTime) * 1000, 2);
                
                Log::info("Job completed: ProcessSamsaraEvent", [
                    'trace_id' => $traceId,
                    'event_id' => $this->event->id,
                    'samsara_event_id' => $this->event->samsara_event_id,
                    'vehicle_name' => $this->event->vehicle_name,
                    'final_status' => 'completed',
                    'verdict' => $assessment['verdict'] ?? 'unknown',
                    'risk_escalation' => $assessment['risk_escalation'] ?? 'unknown',
                    'notification_attempted' => $notificationExecution['attempted'] ?? false,
                    'has_incident' => $this->event->fresh()->incident_id !== null,
                    'duration_ms' => $jobDuration,
                ]);
            }

        } catch (\Exception $e) {
            $jobDuration = round((microtime(true) - $jobStartTime) * 1000, 2);
            
            Log::error("Job failed: ProcessSamsaraEvent", [
                'trace_id' => $traceId,
                'event_id' => $this->event->id,
                'samsara_event_id' => $this->event->samsara_event_id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'job_attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'will_retry' => $this->attempts() < $this->tries,
                'duration_ms' => $jobDuration,
            ]);

            // Si es el último intento, marcar como fallido
            if ($this->attempts() >= $this->tries) {
                $this->event->markAsFailed($e->getMessage());
            }

            // Re-lanzar la excepción para que Laravel maneje el retry
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     * 
     * Enhanced error handling:
     * - Detailed logging with context
     * - Activity log for audit trail
     * - Admin notification for critical events
     */
    public function failed(\Throwable $exception): void
    {
        // Refresh event to get current state
        $this->event->refresh();
        
        // Detailed error logging
        Log::error("Job failed permanently: ProcessSamsaraEvent", [
            'event_id' => $this->event->id,
            'samsara_event_id' => $this->event->samsara_event_id,
            'company_id' => $this->event->company_id,
            'vehicle_id' => $this->event->vehicle_id,
            'vehicle_name' => $this->event->vehicle_name,
            'event_type' => $this->event->event_type,
            'severity' => $this->event->severity,
            'error' => $exception->getMessage(),
            'error_class' => get_class($exception),
            'error_file' => $exception->getFile(),
            'error_line' => $exception->getLine(),
            'error_trace' => array_slice($exception->getTrace(), 0, 5), // First 5 stack frames
            'total_attempts' => $this->attempts(),
            'max_attempts' => $this->tries,
        ]);

        // Mark event as failed with error message
        $this->event->markAsFailed($exception->getMessage());
        
        // Log activity for audit trail
        try {
            \App\Models\SamsaraEventActivity::logAiAction(
                $this->event->id,
                $this->event->company_id,
                \App\Models\SamsaraEventActivity::ACTION_AI_FAILED,
                [
                    'error' => $exception->getMessage(),
                    'error_class' => get_class($exception),
                    'attempts' => $this->attempts(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning("Failed to log activity for failed job", [
                'event_id' => $this->event->id,
                'activity_error' => $e->getMessage(),
            ]);
        }
        
        // For critical events, we might want to notify admins
        if ($this->event->severity === SamsaraEvent::SEVERITY_CRITICAL) {
            Log::critical("CRITICAL EVENT PROCESSING FAILED", [
                'event_id' => $this->event->id,
                'company_id' => $this->event->company_id,
                'vehicle_name' => $this->event->vehicle_name,
                'event_type' => $this->event->event_type,
                'error' => $exception->getMessage(),
                'action_required' => 'Manual investigation may be needed',
            ]);
            
            // TODO: Implement admin notification (email, Slack, etc.)
            // AdminNotification::send('critical_event_failed', $this->event);
        }
    }
    
    /**
     * Check for correlations with other events after processing.
     * 
     * This method is called after successful AI processing to detect
     * patterns like harsh_braking + panic_button = collision.
     */
    protected function checkCorrelations(): void
    {
        try {
            $correlationService = app(\App\Services\AlertCorrelationService::class);
            $incident = $correlationService->checkCorrelations($this->event);
            
            if ($incident) {
                Log::info("Correlation detected: Event linked to incident", [
                    'event_id' => $this->event->id,
                    'incident_id' => $incident->id,
                    'incident_type' => $incident->incident_type,
                    'is_primary' => $this->event->is_primary_event,
                ]);
                
                // Log activity
                \App\Models\SamsaraEventActivity::logAiAction(
                    $this->event->id,
                    $this->event->company_id,
                    'correlation_detected',
                    [
                        'incident_id' => $incident->id,
                        'incident_type' => $incident->incident_type,
                        'related_events' => $incident->correlations()->count(),
                    ]
                );
            }
        } catch (\Throwable $e) {
            // Don't fail the job if correlation check fails
            Log::warning("Correlation check failed (non-fatal)", [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);
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

                Log::debug("Twilio call_sid persisted for callbacks", [
                    'event_id' => $this->event->id,
                    'call_sid' => $result['call_sid'],
                ]);

                break; // Solo guardamos el primer call_sid
            }
        }
    }

    // Métodos persistEvidenceImages y downloadAndStoreImage están en el trait PersistsEvidenceImages

    /**
     * Actualiza la descripción del evento si se encontró un behavior_name más específico
     * desde el safety event detail.
     * 
     * Esto permite mostrar "Passenger Detection" en lugar de "A safety event occurred".
     */
    private function updateEventDescriptionFromSafetyEvent(array $enrichedPayload): void
    {
        // Obtener el safety_event_detail del payload enriquecido
        $safetyEventDetail = $enrichedPayload['preloaded_data']['safety_event_detail'] 
            ?? $enrichedPayload['safety_event_detail'] 
            ?? null;

        // Obtener el behavior_name del safety_event_detail
        $behaviorName = $safetyEventDetail['behavior_name'] ?? null;

        // Solo actualizar si tenemos un nombre más específico
        if (!$behaviorName) {
            return;
        }

        $currentDescription = $this->event->event_description;
        $genericDescriptions = [
            // Inglés
            'A safety event occurred',
            'a safety event occurred',
            'Safety Event',
            'safety event',
            // Español (traducción del webhook)
            'Evento de seguridad',
            'evento de seguridad',
            // Vacíos
            null,
            '',
        ];

        $isGeneric = in_array($currentDescription, $genericDescriptions, true) 
            || str_contains(strtolower($currentDescription ?? ''), 'safety event occurred')
            || str_contains(strtolower($currentDescription ?? ''), 'evento de seguridad');

        if ($isGeneric) {
            // Traducir el behavior name al español
            $translatedName = $this->translateBehaviorName($behaviorName);
            
            $this->event->update([
                'event_description' => $translatedName,
            ]);

            Log::info("Event description updated from safety event detail", [
                'event_id' => $this->event->id,
                'original' => $currentDescription,
                'updated' => $translatedName,
                'behavior_name' => $behaviorName,
            ]);
        }
    }

    /**
     * Traduce los nombres de behavior labels de Samsara al español.
     */
    private function translateBehaviorName(string $behaviorName): string
    {
        $translations = [
            // Detección de comportamiento
            'Passenger Detection' => 'Detección de pasajero',
            'Driver Detection' => 'Detección de conductor',
            'No Driver Detected' => 'Conductor no detectado',
            'Distracted Driving' => 'Conducción distraída',
            'Drowsiness' => 'Somnolencia',
            'Cell Phone Use' => 'Uso de celular',
            'Cell Phone' => 'Uso de celular',
            'Smoking' => 'Fumando',
            'Eating' => 'Comiendo',
            'Yawning' => 'Bostezando',
            'Eyes Closed' => 'Ojos cerrados',
            'No Seatbelt' => 'Sin cinturón de seguridad',
            'Obstructed Camera' => 'Cámara obstruida',
            
            // Eventos de manejo
            'Hard Braking' => 'Frenado brusco',
            'Harsh Braking' => 'Frenado brusco',
            'Hard Acceleration' => 'Aceleración brusca',
            'Harsh Acceleration' => 'Aceleración brusca',
            'Sharp Turn' => 'Giro brusco',
            'Harsh Turn' => 'Giro brusco',
            'Speeding' => 'Exceso de velocidad',
            
            // Eventos de seguridad
            'Collision' => 'Colisión',
            'Near Collision' => 'Casi colisión',
            'Following Distance' => 'Distancia de seguimiento',
            'Rolling Stop' => 'Alto sin detenerse',
            'Stop Sign Violation' => 'Violación de señal de alto',
            'Lane Departure' => 'Salida de carril',
            'Forward Collision Warning' => 'Advertencia de colisión frontal',
            
            // Otros
            'Panic Button' => 'Botón de pánico',
            'Tampering' => 'Manipulación de equipo',
        ];

        return $translations[$behaviorName] ?? $behaviorName;
    }

    /**
     * Pre-carga TODA la información de Samsara en paralelo.
     * 
     * Esto reduce significativamente el tiempo de ejecución del AI Service
     * al proporcionar toda la información necesaria de antemano:
     * - vehicle_info: Información estática del vehículo
     * - driver_assignment: Conductor asignado en el momento
     * - vehicle_stats: GPS, velocidad, movimiento
     * - safety_events_correlation: Otros eventos en la ventana de tiempo
     * - safety_event_detail: Detalle del evento específico (si es safety event)
     * - camera_media: URLs de imágenes (el análisis Vision se hace en AI)
     * 
     * @param array $payload El payload original del webhook
     * @param SamsaraClient $samsaraClient Cliente de la API de Samsara
     * @return array El payload enriquecido con preloaded_data
     */
    private function preloadSamsaraData(array $payload, SamsaraClient $samsaraClient): array
    {
        // Extraer contexto del evento (vehicleId, happenedAtTime)
        $context = SamsaraClient::extractEventContext($payload);
        
        Log::debug("Preload: extractEventContext result", [
            'event_id' => $this->event->id,
            'context' => $context,
            'has_data_conditions' => isset($payload['data']['conditions']),
            'eventType' => $payload['eventType'] ?? 'unknown',
        ]);
        
        if (!$context) {
            Log::warning("Could not extract event context for preload", [
                'event_id' => $this->event->id,
                'payload_keys' => array_keys($payload),
                'data_keys' => array_keys($payload['data'] ?? []),
            ]);
            return $payload;
        }

        $vehicleId = $context['vehicle_id'];
        $eventTime = $context['happened_at_time'];
        $isSafetyEvent = SamsaraClient::isSafetyEvent($payload);

        Log::info("Pre-loading Samsara data", [
            'event_id' => $this->event->id,
            'vehicle_id' => $vehicleId,
            'event_time' => $eventTime,
            'is_safety_event' => $isSafetyEvent,
        ]);

        // Pre-cargar toda la información en paralelo
        $preloadedData = $samsaraClient->preloadAllData(
            vehicleId: $vehicleId,
            eventTime: $eventTime,
            isSafetyEvent: $isSafetyEvent
        );

        if (empty($preloadedData)) {
            Log::warning("No data preloaded from Samsara API", [
                'event_id' => $this->event->id,
            ]);
            return $payload;
        }

        // Añadir datos pre-cargados al payload
        $payload['preloaded_data'] = $preloadedData;

        // También extraer campos útiles a nivel superior para fácil acceso
        
        // Driver del safety event detail o del assignment
        if (!empty($preloadedData['safety_event_detail']['driver']['id'])) {
            $payload['driver'] = $preloadedData['safety_event_detail']['driver'];
        } elseif (!empty($preloadedData['driver_assignment']['driver']['id'])) {
            $payload['driver'] = $preloadedData['driver_assignment']['driver'];
        }
        
        // Behavior label del safety event
        if (!empty($preloadedData['safety_event_detail']['behavior_label'])) {
            $payload['behavior_label'] = $preloadedData['safety_event_detail']['behavior_label'];
        }
        
        // Severity de Samsara
        if (!empty($preloadedData['safety_event_detail']['severity'])) {
            $payload['samsara_severity'] = $preloadedData['safety_event_detail']['severity'];
        }

        // Copiar safety_event_detail a nivel superior para compatibilidad
        if (!empty($preloadedData['safety_event_detail'])) {
            $payload['safety_event_detail'] = $preloadedData['safety_event_detail'];
        }

        Log::info("Samsara data preloaded successfully", [
            'event_id' => $this->event->id,
            'has_vehicle_info' => !empty($preloadedData['vehicle_info']),
            'has_driver' => !empty($preloadedData['driver_assignment']['driver']),
            'has_vehicle_stats' => !empty($preloadedData['vehicle_stats']),
            'safety_events_count' => $preloadedData['safety_events_correlation']['total_events'] ?? 0,
            'camera_items_count' => $preloadedData['camera_media']['total_items'] ?? 0,
            'duration_ms' => $preloadedData['_metadata']['duration_ms'] ?? null,
        ]);

        return $payload;
    }
}

