<?php

namespace App\Jobs;

use App\Jobs\Traits\PersistsEvidenceImages;
use App\Models\SamsaraEvent;
use App\Pulse\Recorders\AiServiceRecorder;
use App\Pulse\Recorders\AlertProcessingRecorder;
use App\Services\ContactResolver;
use App\Services\SamsaraClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Job para revalidar eventos de Samsara que requieren monitoreo continuo.
 * 
 * NOTA: Las notificaciones se ejecutan en Laravel via SendNotificationJob,
 * no en el AI Service. El AI Service solo devuelve la decisión.
 * 
 * Logs de este job van al canal 'revalidation' para diagnóstico independiente.
 */
class RevalidateSamsaraEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, PersistsEvidenceImages;
    use Traits\LogsWithTenantContext;

    /**
     * Número de intentos antes de fallar
     */
    public $tries = 2;

    /**
     * Timeout en segundos (7 minutos)
     * 
     * El HTTP timeout es 5 minutos, más margen para:
     * - Recarga de datos de Samsara (~1-2s)
     * - Persistencia de imágenes (~5-10s)
     * - Overhead de procesamiento
     */
    public $timeout = 420;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public SamsaraEvent $event
    ) {
        $this->onQueue('samsara-revalidation');
    }

    /**
     * Log helper que escribe tanto al canal revalidation como al default.
     */
    private function log(string $level, string $message, array $context = []): void
    {
        // Siempre incluir event_id y timestamp
        $context = array_merge([
            'event_id' => $this->event->id,
            'job_attempt' => $this->attempts(),
            'timestamp' => now()->toIso8601String(),
        ], $context);

        // Log al canal específico de revalidation
        Log::channel('revalidation')->{$level}("[REVALIDATION] {$message}", $context);
        
        // También al canal default para trazabilidad general
        Log::{$level}("[REVALIDATION] {$message}", $context);
    }

    /**
     * Execute the job.
     */
    public function handle(ContactResolver $contactResolver): void
    {
        $jobStartTime = microtime(true);
        
        // Registrar contexto de empresa para todos los logs de este job
        $this->setLogContext($this->event->company);
        
        $this->log('info', '========== REVALIDATION JOB STARTED ==========', [
            'trace_id' => $this->getTraceId(),
            'samsara_event_id' => $this->event->samsara_event_id,
            'vehicle_name' => $this->event->vehicle_name,
            'current_ai_status' => $this->event->ai_status,
            'investigation_count' => $this->event->investigation_count,
            'last_investigation_at' => $this->event->last_investigation_at?->toIso8601String(),
            'occurred_at' => $this->event->occurred_at?->toIso8601String(),
        ]);

        // Verificar que el evento aún esté en investigating
        if ($this->event->ai_status !== SamsaraEvent::STATUS_INVESTIGATING) {
            $this->log('warning', 'Event no longer in INVESTIGATING status - SKIPPING', [
                'expected_status' => SamsaraEvent::STATUS_INVESTIGATING,
                'actual_status' => $this->event->ai_status,
                'reason' => 'El evento cambió de estado antes de que se ejecutara la revalidación',
            ]);
            return;
        }

        $this->log('debug', 'Status check passed - event is still INVESTIGATING');

        // Verificar límite de investigaciones (configurable por empresa)
        $maxInvestigations = SamsaraEvent::getMaxInvestigations($this->event->company);
        if ($this->event->investigation_count >= $maxInvestigations) {
            $this->log('warning', 'Max investigations limit reached - marking as needs_review', [
                'investigation_count' => $this->event->investigation_count,
                'max_investigations' => $maxInvestigations,
            ]);

            $this->event->markAsCompleted(
                assessment: array_merge($this->event->ai_assessment ?? [], [
                    'verdict' => 'needs_review',
                    'likelihood' => 'medium',
                    'confidence' => 0.5,
                    'reasoning' => 'Máximo de revalidaciones alcanzado. Requiere revisión humana.',
                    'risk_escalation' => 'warn',
                    'requires_monitoring' => false,
                ]),
                humanMessage: 'Este evento requiere revisión manual después de ' . $maxInvestigations . ' análisis automáticos.',
                alertContext: $this->event->alert_context,
                notificationDecision: $this->event->notification_decision,
                notificationExecution: null,
                execution: $this->event->ai_actions
            );

            $this->log('info', 'Event marked as needs_review due to max investigations');
            return;
        }

        $this->log('debug', 'Investigation limit check passed', [
            'current_count' => $this->event->investigation_count,
            'max_allowed' => $maxInvestigations,
        ]);

        try {
            // =========================================================
            // PASO 0: Obtener configuración AI de la empresa
            // =========================================================
            $company = $this->event->company;
            $aiConfig = $company->getAiConfig();
            $timeWindows = $aiConfig['investigation_windows'] ?? [];

            $this->log('debug', 'STEP 0: Company AI config loaded', [
                'company_id' => $company->id,
                'has_custom_time_windows' => !empty($company->getSetting('ai_config.investigation_windows')),
            ]);

            // =========================================================
            // PASO 1: Resolver contactos para notificaciones
            // =========================================================
            $this->log('debug', 'STEP 1: Resolving contacts for notifications');
            
            $contacts = $contactResolver->resolveForEvent($this->event);
            $contactPayload = $contactResolver->formatForPayload($contacts);

            $this->log('debug', 'Contacts resolved', [
                'contacts_count' => count($contacts),
                'contact_payload_keys' => array_keys($contactPayload),
            ]);

            // Enriquecer el payload con los contactos
            $enrichedPayload = array_merge($this->event->raw_payload, $contactPayload);

            // =========================================================
            // PASO 2: Recargar datos de Samsara con ventana temporal actualizada
            // =========================================================
            $this->log('debug', 'STEP 2: Reloading Samsara data with updated time window');
            
            $enrichedPayload = $this->reloadSamsaraDataForRevalidation($enrichedPayload, $timeWindows);

            // =========================================================
            // PASO 3: Preparar contexto de revalidación
            // =========================================================
            $this->log('debug', 'STEP 3: Preparing revalidation context');

            $aiServiceUrl = config('services.ai_engine.url');
            $currentRevalidationTime = now()->toIso8601String();

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

            $this->log('info', 'Revalidation context prepared', [
                'ai_service_url' => $aiServiceUrl,
                'context_keys' => array_keys($revalidationContext),
                'previous_verdict' => $this->event->ai_assessment['verdict'] ?? 'none',
                'previous_risk_escalation' => $this->event->ai_assessment['risk_escalation'] ?? 'none',
                'history_entries' => count($revalidationContext['investigation_history']),
            ]);

            // =========================================================
            // PASO 4: Llamar al AI Service
            // =========================================================
            // Timeout aumentado a 5 minutos porque el pipeline de revalidación
            // puede tardar más debido a:
            // - Llamadas múltiples al LLM (investigator + final + notification)
            // - Análisis de imágenes de cámara con Vision
            // - Carga del servicio de OpenAI
            $httpTimeout = 300; // 5 minutos
            
            // Agregar company_id y AI config al payload para trazabilidad multi-tenant
            $enrichedPayload['company_id'] = $this->event->company_id;
            $enrichedPayload['company_config'] = $aiConfig;

            $this->log('info', 'STEP 4: Calling AI Service /alerts/revalidate', [
                'endpoint' => "{$aiServiceUrl}/alerts/revalidate",
                'timeout_seconds' => $httpTimeout,
                'trace_id' => $this->getTraceId(),
            ]);

            $requestStartTime = microtime(true);

            $response = Http::timeout($httpTimeout)
                ->withHeaders([
                    'X-Trace-ID' => $this->getTraceId(), // Propagar trace_id para trazabilidad distribuida
                ])
                ->post("{$aiServiceUrl}/alerts/revalidate", [
                    'event_id' => $this->event->id,
                    'payload' => $enrichedPayload,
                    'context' => $revalidationContext,
                ]);

            $requestDuration = round((microtime(true) - $requestStartTime) * 1000, 2);

            // Registrar métricas del AI Service en Pulse
            AiServiceRecorder::recordAiServiceCall(
                endpoint: '/alerts/revalidate',
                success: $response->successful(),
                durationMs: (int) $requestDuration,
                statusCode: $response->status()
            );

            $this->log('debug', 'AI Service response received', [
                'status_code' => $response->status(),
                'duration_ms' => $requestDuration,
                'response_size_bytes' => strlen($response->body()),
            ]);

            // Manejar 503 (Service at Capacity)
            if ($response->status() === 503) {
                $stats = $response->json('stats', []);
                $this->log('warning', 'AI Service at capacity - will retry', [
                    'status_code' => 503,
                    'ai_stats' => $stats,
                ]);
                $activeRequests = $stats['active_requests'] ?? '?';
                $pendingRequests = $stats['pending_requests'] ?? '?';
                throw new \Exception("AI service at capacity. Active: {$activeRequests}, Pending: {$pendingRequests}");
            }

            if ($response->failed()) {
                $this->log('error', 'AI Service returned HTTP error', [
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 1000),
                ]);
                throw new \Exception("AI service returned HTTP error: " . $response->body());
            }

            $result = $response->json();

            $this->log('info', 'AI Service response parsed successfully', [
                'status' => $result['status'] ?? 'unknown',
                'has_alert_context' => isset($result['alert_context']),
                'has_assessment' => isset($result['assessment']),
                'has_human_message' => isset($result['human_message']),
                'has_notification_decision' => isset($result['notification_decision']),
                'has_execution' => isset($result['execution']),
                'has_camera_analysis' => isset($result['camera_analysis']),
            ]);

            // =========================================================
            // VALIDACIÓN CRÍTICA: Verificar status del JSON
            // El AI Service puede devolver HTTP 200 pero con status "error"
            // si el pipeline falló internamente
            // =========================================================
            if (($result['status'] ?? 'unknown') === 'error') {
                $errorMessage = $result['error'] ?? 'Unknown pipeline error';
                $this->log('error', 'AI Service returned error status in JSON', [
                    'error' => $errorMessage,
                    'result_keys' => array_keys($result),
                ]);
                throw new \Exception("AI service pipeline error: {$errorMessage}");
            }

            // Validar que tenemos un assessment válido con los campos requeridos
            $assessment = $result['assessment'] ?? [];
            if (empty($assessment) || !isset($assessment['verdict'])) {
                $this->log('error', 'AI Service returned empty or invalid assessment', [
                    'has_assessment' => !empty($assessment),
                    'has_verdict' => isset($assessment['verdict']),
                    'assessment_keys' => array_keys($assessment),
                ]);
                throw new \Exception("AI service returned invalid assessment: missing verdict");
            }

            // =========================================================
            // PASO 5: Extraer y procesar datos de respuesta
            // =========================================================
            $this->log('debug', 'STEP 5: Extracting response data');

            $alertContext = $result['alert_context'] ?? $this->event->alert_context;
            // $assessment ya fue validado arriba
            $humanMessage = $result['human_message'] ?? 'Revalidación completada';
            $notificationDecision = $result['notification_decision'] ?? null;
            // NOTA: notification_execution ya no viene del AI Service
            // Las notificaciones se ejecutan en Laravel via SendNotificationJob
            $execution = $result['execution'] ?? $this->event->ai_actions;
            $cameraAnalysis = $result['camera_analysis'] ?? null;

            $this->log('debug', 'Assessment extracted from response', [
                'verdict' => $assessment['verdict'] ?? 'NOT_PRESENT',
                'likelihood' => $assessment['likelihood'] ?? 'NOT_PRESENT',
                'confidence' => $assessment['confidence'] ?? 'NOT_PRESENT',
                'risk_escalation' => $assessment['risk_escalation'] ?? 'NOT_PRESENT',
                'requires_monitoring' => $assessment['requires_monitoring'] ?? 'NOT_PRESENT',
                'next_check_minutes' => $assessment['next_check_minutes'] ?? 'NOT_PRESENT',
                'monitoring_reason' => $assessment['monitoring_reason'] ?? 'NOT_PRESENT',
            ]);

            // Persistir imágenes de evidencia si existen
            $this->log('debug', 'Persisting evidence images if any');
            [$execution, $cameraAnalysis] = $this->persistEvidenceImages($execution, $cameraAnalysis);

            if ($cameraAnalysis) {
                $execution['camera_analysis'] = $cameraAnalysis;
            }

            // Extraer información de monitoreo desde el assessment
            $requiresMonitoring = $assessment['requires_monitoring'] ?? false;
            $nextCheckMinutes = $assessment['next_check_minutes'] ?? 30;
            $monitoringReason = $assessment['monitoring_reason'] ?? null;

            $this->log('info', 'Monitoring decision extracted', [
                'requires_monitoring' => $requiresMonitoring,
                'next_check_minutes' => $nextCheckMinutes,
                'monitoring_reason' => $monitoringReason,
            ]);

            // =========================================================
            // PASO 6: Actualizar evento según resultado
            // =========================================================
            if ($requiresMonitoring) {
                $this->log('info', 'STEP 6: Event CONTINUES under investigation', [
                    'action' => 'markAsInvestigating',
                    'next_check_minutes' => $nextCheckMinutes,
                ]);

                // T3: Transacción para garantizar consistencia entre evento y tablas normalizadas
                DB::transaction(function () use ($assessment, $humanMessage, $nextCheckMinutes, $alertContext, $notificationDecision, $execution) {
                    $this->event->markAsInvestigating(
                        assessment: $assessment,
                        humanMessage: $humanMessage,
                        nextCheckMinutes: $nextCheckMinutes,
                        alertContext: $alertContext,
                        notificationDecision: $notificationDecision,
                        notificationExecution: null, // Notificaciones se ejecutan via SendNotificationJob
                        execution: $execution
                    );

                    // T3: Tablas normalizadas como fuente de verdad única
                    $this->event->saveRecommendedActions($assessment['recommended_actions'] ?? []);
                    $this->event->saveInvestigationSteps($alertContext['investigation_plan'] ?? []);
                });

                $this->event->addInvestigationRecord(
                    reason: $monitoringReason ?? 'Requiere más tiempo para contexto'
                );

                // Despachar job de notificaciones si hay decisión de notificar
                if ($notificationDecision && ($notificationDecision['should_notify'] ?? false)) {
                    SendNotificationJob::dispatch($this->event, $notificationDecision);
                    
                    $this->log('info', 'SendNotificationJob dispatched (revalidation - continues monitoring)', [
                        'escalation_level' => $notificationDecision['escalation_level'] ?? 'unknown',
                        'channels' => $notificationDecision['channels_to_use'] ?? [],
                    ]);
                }

                // =========================================================
                // PASO 7: Programar siguiente revalidación
                // =========================================================
                $scheduledAt = now()->addMinutes($nextCheckMinutes);
                
                $this->log('info', 'STEP 7: Scheduling NEXT revalidation', [
                    'next_check_minutes' => $nextCheckMinutes,
                    'scheduled_at' => $scheduledAt->toIso8601String(),
                    'queue' => 'samsara-revalidation',
                    'new_investigation_count' => $this->event->investigation_count,
                ]);

                self::dispatch($this->event)
                    ->delay($scheduledAt)
                    ->onQueue('samsara-revalidation');

                $jobDuration = round((microtime(true) - $jobStartTime) * 1000, 2);
                
                $this->log('info', '========== REVALIDATION JOB COMPLETED - CONTINUES MONITORING ==========', [
                    'trace_id' => $this->getTraceId(),
                    'verdict' => $assessment['verdict'] ?? 'unknown',
                    'risk_escalation' => $assessment['risk_escalation'] ?? 'unknown',
                    'next_revalidation_at' => $scheduledAt->toIso8601String(),
                    'duration_ms' => $jobDuration,
                ]);

                // Registrar métricas de procesamiento en Pulse
                AlertProcessingRecorder::recordAlertProcessing(
                    event: $this->event,
                    durationMs: (int) $jobDuration,
                    status: 'investigating'
                );

            } else {
                $this->log('info', 'STEP 6: Event investigation COMPLETED', [
                    'action' => 'markAsCompleted',
                    'final_verdict' => $assessment['verdict'] ?? 'unknown',
                ]);

                // T3: Transacción para garantizar consistencia entre evento y tablas normalizadas
                DB::transaction(function () use ($assessment, $humanMessage, $alertContext, $notificationDecision, $execution) {
                    $this->event->markAsCompleted(
                        assessment: $assessment,
                        humanMessage: $humanMessage,
                        alertContext: $alertContext,
                        notificationDecision: $notificationDecision,
                        notificationExecution: null, // Notificaciones se ejecutan via SendNotificationJob
                        execution: $execution
                    );

                    // T3: Tablas normalizadas como fuente de verdad única
                    $this->event->saveRecommendedActions($assessment['recommended_actions'] ?? []);
                    $this->event->saveInvestigationSteps($alertContext['investigation_plan'] ?? []);
                });

                // Despachar job de notificaciones si hay decisión de notificar
                $notificationDispatched = false;
                if ($notificationDecision && ($notificationDecision['should_notify'] ?? false)) {
                    SendNotificationJob::dispatch($this->event, $notificationDecision);
                    $notificationDispatched = true;
                    
                    $this->log('info', 'SendNotificationJob dispatched (revalidation - completed)', [
                        'escalation_level' => $notificationDecision['escalation_level'] ?? 'unknown',
                        'channels' => $notificationDecision['channels_to_use'] ?? [],
                    ]);
                }

                $jobDuration = round((microtime(true) - $jobStartTime) * 1000, 2);
                
                $this->log('info', '========== REVALIDATION JOB COMPLETED - INVESTIGATION FINISHED ==========', [
                    'trace_id' => $this->getTraceId(),
                    'final_verdict' => $assessment['verdict'] ?? 'unknown',
                    'final_risk_escalation' => $assessment['risk_escalation'] ?? 'unknown',
                    'total_investigations' => $this->event->investigation_count,
                    'notification_dispatched' => $notificationDispatched,
                    'duration_ms' => $jobDuration,
                ]);

                // Registrar métricas de procesamiento en Pulse
                AlertProcessingRecorder::recordAlertProcessing(
                    event: $this->event,
                    durationMs: (int) $jobDuration,
                    status: 'completed'
                );
            }

        } catch (\Exception $e) {
            $jobDuration = round((microtime(true) - $jobStartTime) * 1000, 2);
            
            $this->log('error', 'REVALIDATION JOB FAILED', [
                'trace_id' => $this->getTraceId(),
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'will_retry' => $this->attempts() < $this->tries,
                'duration_ms' => $jobDuration,
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
        // Registrar contexto de empresa para el log de fallo
        $this->setLogContext($this->event->company);
        
        $this->log('error', '========== REVALIDATION JOB FAILED PERMANENTLY ==========', [
            'trace_id' => $this->getTraceId(),
            'error_message' => $exception->getMessage(),
            'error_class' => get_class($exception),
            'total_attempts' => $this->attempts(),
        ]);
    }

    /**
     * Persiste la información de la ventana de tiempo consultada en el historial.
     */
    private function persistRevalidationWindow(array $reloadedData): void
    {
        $metadata = $reloadedData['_metadata'] ?? [];
        $queryWindow = $metadata['query_window'] ?? [];
        
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
            'safety_events_details' => $this->summarizeSafetyEvents(
                $reloadedData['safety_events_since_last_check']['events'] ?? []
            ),
        ];
        
        $history = $this->event->investigation_history ?? [];
        $history[] = $windowSummary;
        
        $this->event->update(['investigation_history' => $history]);
        
        $this->log('debug', 'Window persisted to investigation_history', [
            'investigation_number' => $windowSummary['investigation_number'],
            'window_start' => $windowSummary['time_window']['start'],
            'window_end' => $windowSummary['time_window']['end'],
            'new_safety_events' => $windowSummary['findings']['new_safety_events'],
            'new_camera_items' => $windowSummary['findings']['new_camera_items'],
        ]);
    }
    
    /**
     * Resume los safety events encontrados para el historial.
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
        }, array_slice($events, 0, 5));
    }

    /**
     * Recarga datos de Samsara con una ventana temporal ACTUALIZADA.
     * 
     * @param array $payload El payload original
     * @param array $timeWindows Time window configuration from company settings
     * @return array Payload con datos actualizados
     */
    private function reloadSamsaraDataForRevalidation(array $payload, array $timeWindows = []): array
    {
        $company = $this->event->company;
        if (!$company) {
            $this->log('warning', 'No company found - skipping Samsara data reload', [
                'company_id' => $this->event->company_id,
            ]);
            return $payload;
        }

        $samsaraApiKey = $company->getSamsaraApiKey();
        if (empty($samsaraApiKey)) {
            $this->log('warning', 'No Samsara API key configured - skipping reload', [
                'company_id' => $company->id,
                'company_name' => $company->name,
            ]);
            return $payload;
        }

        $context = SamsaraClient::extractEventContext($payload);
        if (!$context) {
            $this->log('warning', 'Could not extract event context from payload - skipping reload');
            return $payload;
        }

        $vehicleId = $context['vehicle_id'];
        $originalEventTime = $context['happened_at_time'];
        $lastInvestigationTime = $this->event->last_investigation_at?->toIso8601String() 
            ?? $this->event->created_at->toIso8601String();
        $isSafetyEvent = SamsaraClient::isSafetyEvent($payload);

        $this->log('info', 'Reloading Samsara data with updated time window', [
            'vehicle_id' => $vehicleId,
            'original_event_time' => $originalEventTime,
            'last_investigation_time' => $lastInvestigationTime,
            'is_safety_event' => $isSafetyEvent,
        ]);

        try {
            $samsaraClient = new SamsaraClient($samsaraApiKey);
            $reloadedData = $samsaraClient->reloadDataForRevalidation(
                vehicleId: $vehicleId,
                originalEventTime: $originalEventTime,
                lastInvestigationTime: $lastInvestigationTime,
                isSafetyEvent: $isSafetyEvent,
                timeWindows: $timeWindows
            );

            if (empty($reloadedData)) {
                $this->log('warning', 'No data reloaded from Samsara API');
                return $payload;
            }

            $payload['revalidation_data'] = $reloadedData;

            if (!empty($reloadedData['vehicle_info'])) {
                $payload['preloaded_data']['vehicle_info'] = $reloadedData['vehicle_info'];
            }
            if (!empty($reloadedData['driver_assignment'])) {
                $payload['preloaded_data']['driver_assignment'] = $reloadedData['driver_assignment'];
            }

            $this->persistRevalidationWindow($reloadedData);

            $payload['revalidation_windows_history'] = $this->event->fresh()->investigation_history ?? [];

            $this->log('info', 'Samsara data reloaded successfully', [
                'has_new_vehicle_stats' => !empty($reloadedData['vehicle_stats_since_last_check']),
                'new_safety_events_count' => $reloadedData['safety_events_since_last_check']['total_events'] ?? 0,
                'new_camera_items_count' => $reloadedData['camera_media_since_last_check']['total_items'] ?? 0,
                'query_window_minutes' => $reloadedData['_metadata']['query_window']['minutes_covered'] ?? null,
            ]);

            return $payload;

        } catch (\Exception $e) {
            $this->log('error', 'Failed to reload Samsara data', [
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
            return $payload;
        }
    }

    // NOTA: persistTwilioCallSid fue removido porque ahora SendNotificationJob maneja esto
}
