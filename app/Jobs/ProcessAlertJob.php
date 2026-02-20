<?php

namespace App\Jobs;

use App\Jobs\Traits\PersistsEvidenceImages;
use App\Models\Alert;
use App\Models\AlertActivity;
use App\Models\AlertAi;
use App\Models\AlertMetrics;
use App\Models\SafetySignal;
use App\Pulse\Recorders\AiServiceRecorder;
use App\Pulse\Recorders\AlertProcessingRecorder;
use App\Services\AttentionEngine;
use App\Services\ContactResolver;
use App\Services\DomainEventEmitter;
use App\Services\MonitorMatrixOverride;
use App\Services\Incidents\IncidentCreationGate;
use App\Samsara\Client\PipelineAdapter;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, PersistsEvidenceImages;
    use Traits\LogsWithTenantContext;

    public $tries = 3;
    public $timeout = 300;
    public $backoff = [30, 60, 120];

    public function __construct(
        public Alert $alert
    ) {
        $this->onQueue('samsara-events');
    }

    public function handle(ContactResolver $contactResolver, MonitorMatrixOverride $monitorMatrixOverride): void
    {
        $this->alert->refresh();
        $this->alert->load('signal');

        $company = $this->alert->company;
        $signal = $this->alert->signal;

        $this->setLogContext($company);
        $jobStartTime = microtime(true);
        $traceId = $this->getTraceId();

        Log::info("Job started: ProcessAlert", [
            'trace_id' => $traceId,
            'alert_id' => $this->alert->id,
            'signal_id' => $signal->id,
            'samsara_event_id' => $signal->samsara_event_id,
            'event_type' => $signal->event_type,
            'vehicle_name' => $signal->vehicle_name,
            'severity' => $this->alert->severity,
            'current_status' => $this->alert->ai_status,
            'job_attempt' => $this->attempts(),
        ]);

        if (in_array($this->alert->ai_status, [Alert::STATUS_COMPLETED, Alert::STATUS_INVESTIGATING])) {
            Log::info("Job skipped: Alert already processed", [
                'alert_id' => $this->alert->id,
                'current_status' => $this->alert->ai_status,
            ]);
            return;
        }

        try {
            $this->alert->markAsProcessing();

            $pipelineStartedAt = now();
            AlertMetrics::updateOrCreate(
                ['alert_id' => $this->alert->id],
                ['pipeline_time_ai_started_at' => $pipelineStartedAt]
            );

            DomainEventEmitter::emit(
                companyId: $this->alert->company_id,
                entityType: 'alert',
                entityId: (string) $this->alert->id,
                eventType: 'alert.processing_started',
                payload: [
                    'event_type' => $signal->event_type,
                    'vehicle_id' => $signal->vehicle_id,
                    'severity' => $this->alert->severity,
                    'attempt' => $this->attempts(),
                ],
                correlationId: (string) $this->alert->id,
            );

            if (!$company) {
                throw new \Exception("Alert has no company associated.");
            }

            $samsaraApiKey = $company->getSamsaraApiKey();
            if (empty($samsaraApiKey)) {
                throw new \Exception("Company {$company->id} does not have Samsara API key configured.");
            }

            $samsaraClient = new PipelineAdapter($samsaraApiKey);

            $contacts = $contactResolver->resolveForAlert($this->alert);
            $contactPayload = $contactResolver->formatForPayload($contacts);

            $rawPayload = $signal->raw_payload ?? [];
            $enrichedPayload = array_merge($rawPayload, $contactPayload);

            $aiConfig = $company->getAiConfig();
            $timeWindows = $aiConfig['investigation_windows'] ?? [];

            $enrichedPayload = $this->preloadSamsaraData($enrichedPayload, $samsaraClient, $timeWindows);
            $this->updateAlertDescriptionFromSafetyEvent($enrichedPayload);

            $aiServiceUrl = config('services.ai_engine.url');
            $enrichedPayload['company_id'] = $company->id;
            $enrichedPayload['company_config'] = $aiConfig;

            $aiRequestStart = microtime(true);

            $response = Http::timeout(300)
                ->withHeaders([
                    'traceparent' => $this->getTraceparent(),
                    'X-Trace-ID' => $traceId,
                ])
                ->post("{$aiServiceUrl}/alerts/ingest", [
                    'event_id' => $this->alert->id,
                    'payload' => $enrichedPayload,
                ]);

            $aiRequestDuration = (int) round((microtime(true) - $aiRequestStart) * 1000);

            AiServiceRecorder::recordAiServiceCall(
                endpoint: '/alerts/ingest',
                success: $response->successful(),
                durationMs: $aiRequestDuration,
                statusCode: $response->status()
            );

            if ($response->status() === 503) {
                $stats = $response->json('stats', []);
                throw new \Exception("AI service at capacity. Active: " . ($stats['active_requests'] ?? '?'));
            }

            if ($response->failed()) {
                throw new \Exception("AI service returned HTTP error: " . $response->body());
            }

            $result = $response->json();

            if (($result['status'] ?? 'unknown') === 'error') {
                throw new \Exception("AI service pipeline error: " . ($result['error'] ?? 'Unknown'));
            }

            $assessment = $result['assessment'] ?? [];
            if (empty($assessment) || !isset($assessment['verdict'])) {
                throw new \Exception("AI service returned invalid assessment: missing verdict");
            }

            $pipelineTimeAiFinished = now();
            $pipelineLatencyMs = (int) $this->alert->created_at->diffInMilliseconds($pipelineTimeAiFinished);
            $execution = $result['execution'] ?? null;

            $metricsData = [
                'pipeline_time_ai_finished_at' => $pipelineTimeAiFinished,
                'pipeline_latency_ms' => $pipelineLatencyMs,
                'ai_tokens' => isset($execution['total_tokens']) ? (int) $execution['total_tokens'] : null,
                'ai_cost_estimate' => isset($execution['cost_estimate']) ? (float) $execution['cost_estimate'] : null,
            ];

            $alertContext = $result['alert_context'] ?? null;
            $humanMessage = $result['human_message'] ?? 'Procesamiento completado';
            $notificationDecision = $result['notification_decision'] ?? null;
            $cameraAnalysis = $result['camera_analysis'] ?? null;

            [$execution, $cameraAnalysis] = $this->persistEvidenceImages($execution, $cameraAnalysis);

            if ($cameraAnalysis) {
                $execution['camera_analysis'] = $cameraAnalysis;
            }

            $requiresMonitoring = $assessment['requires_monitoring'] ?? false;
            $nextCheckMinutes = $assessment['next_check_minutes'] ?? 15;
            $monitoringReason = $assessment['monitoring_reason'] ?? null;

            if ($requiresMonitoring) {
                DB::transaction(function () use ($assessment, $humanMessage, $nextCheckMinutes, $alertContext, $notificationDecision, $execution, $metricsData) {
                    $this->alert->markAsInvestigating(
                        assessment: $assessment,
                        humanMessage: $humanMessage,
                        nextCheckMinutes: $nextCheckMinutes,
                        alertContext: $alertContext,
                        notificationDecision: $notificationDecision,
                        execution: $execution
                    );

                    $this->alert->saveRecommendedActions($assessment['recommended_actions'] ?? []);
                    $this->alert->saveInvestigationSteps($alertContext['investigation_plan'] ?? []);

                    AlertMetrics::updateOrCreate(
                        ['alert_id' => $this->alert->id],
                        $metricsData
                    );
                });

                $this->alert->addInvestigationRecord(
                    reason: $monitoringReason ?? 'Confianza insuficiente para veredicto final'
                );

                DomainEventEmitter::emit(
                    companyId: $this->alert->company_id,
                    entityType: 'alert',
                    entityId: (string) $this->alert->id,
                    eventType: 'alert.investigating',
                    payload: [
                        'verdict' => $assessment['verdict'] ?? null,
                        'likelihood' => $assessment['likelihood'] ?? null,
                        'risk_escalation' => $assessment['risk_escalation'] ?? null,
                        'next_check_minutes' => $nextCheckMinutes,
                    ],
                    correlationId: (string) $this->alert->id,
                );

                $this->emitUsageEvents($metricsData);

                $decisionToSend = $notificationDecision;
                if ($decisionToSend && !($decisionToSend['should_notify'] ?? false)) {
                    $override = $monitorMatrixOverride->apply(
                        $this->alert,
                        $decisionToSend,
                        $assessment,
                        $contactResolver,
                        $humanMessage
                    );
                    if ($override) {
                        $decisionToSend = $override;
                    }
                }
                if ($decisionToSend && ($decisionToSend['should_notify'] ?? false)) {
                    SendNotificationJob::dispatch($this->alert, $decisionToSend);
                }

                $this->initializeAttentionEngine();

                $scheduledAt = now()->addMinutes($nextCheckMinutes);
                RevalidateAlertJob::dispatch($this->alert)
                    ->delay($scheduledAt)
                    ->onQueue('samsara-revalidation');

                $jobDuration = round((microtime(true) - $jobStartTime) * 1000, 2);

                Log::info("Job completed: ProcessAlert (monitoring)", [
                    'alert_id' => $this->alert->id,
                    'final_status' => 'investigating',
                    'next_check_minutes' => $nextCheckMinutes,
                    'verdict' => $assessment['verdict'] ?? 'unknown',
                    'duration_ms' => $jobDuration,
                ]);

                AlertProcessingRecorder::recordAlertProcessing(
                    alert: $this->alert,
                    durationMs: (int) $jobDuration,
                    status: 'investigating'
                );

                $this->createIncidentIfNeeded($assessment);
            } else {
                DB::transaction(function () use ($assessment, $humanMessage, $alertContext, $notificationDecision, $execution, $metricsData) {
                    $this->alert->markAsCompleted(
                        assessment: $assessment,
                        humanMessage: $humanMessage,
                        alertContext: $alertContext,
                        notificationDecision: $notificationDecision,
                        execution: $execution
                    );

                    $this->alert->saveRecommendedActions($assessment['recommended_actions'] ?? []);
                    $this->alert->saveInvestigationSteps($alertContext['investigation_plan'] ?? []);

                    AlertMetrics::updateOrCreate(
                        ['alert_id' => $this->alert->id],
                        $metricsData
                    );
                });

                DomainEventEmitter::emit(
                    companyId: $this->alert->company_id,
                    entityType: 'alert',
                    entityId: (string) $this->alert->id,
                    eventType: 'alert.completed',
                    payload: [
                        'verdict' => $assessment['verdict'] ?? null,
                        'likelihood' => $assessment['likelihood'] ?? null,
                        'risk_escalation' => $assessment['risk_escalation'] ?? null,
                    ],
                    correlationId: (string) $this->alert->id,
                );

                $this->emitUsageEvents($metricsData);

                $decisionToSend = $notificationDecision;
                if ($decisionToSend && !($decisionToSend['should_notify'] ?? false)) {
                    $override = $monitorMatrixOverride->apply(
                        $this->alert,
                        $decisionToSend,
                        $assessment,
                        $contactResolver,
                        $humanMessage
                    );
                    if ($override) {
                        $decisionToSend = $override;
                    }
                }
                if ($decisionToSend && ($decisionToSend['should_notify'] ?? false)) {
                    SendNotificationJob::dispatch($this->alert, $decisionToSend);
                }

                $this->initializeAttentionEngine();

                $jobDuration = round((microtime(true) - $jobStartTime) * 1000, 2);

                Log::info("Job completed: ProcessAlert", [
                    'alert_id' => $this->alert->id,
                    'final_status' => 'completed',
                    'verdict' => $assessment['verdict'] ?? 'unknown',
                    'duration_ms' => $jobDuration,
                ]);

                AlertProcessingRecorder::recordAlertProcessing(
                    alert: $this->alert,
                    durationMs: (int) $jobDuration,
                    status: 'completed'
                );

                $this->createIncidentIfNeeded($assessment);
            }

        } catch (\Exception $e) {
            $jobDuration = round((microtime(true) - $jobStartTime) * 1000, 2);

            Log::error("Job failed: ProcessAlert", [
                'alert_id' => $this->alert->id,
                'error' => $e->getMessage(),
                'job_attempt' => $this->attempts(),
                'will_retry' => $this->attempts() < $this->tries,
                'duration_ms' => $jobDuration,
            ]);

            if ($this->attempts() >= $this->tries) {
                AlertMetrics::updateOrCreate(
                    ['alert_id' => $this->alert->id],
                    [
                        'pipeline_time_ai_finished_at' => now(),
                        'pipeline_latency_ms' => (int) $this->alert->created_at->diffInMilliseconds(now()),
                    ]
                );
                $this->alert->markAsFailed($e->getMessage());

                DomainEventEmitter::emit(
                    companyId: $this->alert->company_id,
                    entityType: 'alert',
                    entityId: (string) $this->alert->id,
                    eventType: 'alert.failed',
                    payload: ['error' => $e->getMessage(), 'attempts' => $this->attempts()],
                    correlationId: (string) $this->alert->id,
                );

                AlertProcessingRecorder::recordAlertProcessing(
                    alert: $this->alert,
                    durationMs: (int) $jobDuration,
                    status: 'failed'
                );
            }

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->alert->refresh();

        Log::error("Job failed permanently: ProcessAlert", [
            'alert_id' => $this->alert->id,
            'company_id' => $this->alert->company_id,
            'error' => $exception->getMessage(),
        ]);

        $this->alert->markAsFailed($exception->getMessage());

        try {
            AlertActivity::logAiAction(
                $this->alert->id,
                $this->alert->company_id,
                AlertActivity::ACTION_AI_FAILED,
                [
                    'error' => $exception->getMessage(),
                    'attempts' => $this->attempts(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning("Failed to log activity for failed job", [
                'alert_id' => $this->alert->id,
                'activity_error' => $e->getMessage(),
            ]);
        }

        if ($this->alert->severity === Alert::SEVERITY_CRITICAL) {
            Log::critical("CRITICAL ALERT PROCESSING FAILED", [
                'alert_id' => $this->alert->id,
                'company_id' => $this->alert->company_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function updateAlertDescriptionFromSafetyEvent(array $enrichedPayload): void
    {
        $safetyEventDetail = $enrichedPayload['preloaded_data']['safety_event_detail']
            ?? $enrichedPayload['safety_event_detail']
            ?? null;

        $behaviorName = $safetyEventDetail['behavior_name'] ?? null;
        if (!$behaviorName) {
            return;
        }

        $signal = $this->alert->signal;
        $currentDescription = $signal->event_description ?? $this->alert->event_description;

        $genericDescriptions = [
            'A safety event occurred', 'a safety event occurred',
            'Safety Event', 'safety event',
            'Evento de seguridad', 'evento de seguridad',
            null, '',
        ];

        $isGeneric = in_array($currentDescription, $genericDescriptions, true)
            || str_contains(strtolower($currentDescription ?? ''), 'safety event occurred')
            || str_contains(strtolower($currentDescription ?? ''), 'evento de seguridad');

        if ($isGeneric) {
            $translatedName = $this->translateBehaviorName($behaviorName);
            $signal->update(['event_description' => $translatedName]);
            $this->alert->update(['event_description' => $translatedName]);
        }
    }

    private function translateBehaviorName(string $behaviorName): string
    {
        $translations = [
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
            'Hard Braking' => 'Frenado brusco',
            'Harsh Braking' => 'Frenado brusco',
            'Hard Acceleration' => 'Aceleración brusca',
            'Harsh Acceleration' => 'Aceleración brusca',
            'Sharp Turn' => 'Giro brusco',
            'Harsh Turn' => 'Giro brusco',
            'Speeding' => 'Exceso de velocidad',
            'Collision' => 'Colisión',
            'Near Collision' => 'Casi colisión',
            'Following Distance' => 'Distancia de seguimiento',
            'Rolling Stop' => 'Alto sin detenerse',
            'Stop Sign Violation' => 'Violación de señal de alto',
            'Lane Departure' => 'Salida de carril',
            'Forward Collision Warning' => 'Advertencia de colisión frontal',
            'Panic Button' => 'Botón de pánico',
            'Tampering' => 'Manipulación de equipo',
        ];

        return $translations[$behaviorName] ?? $behaviorName;
    }

    private function preloadSamsaraData(array $payload, PipelineAdapter $samsaraClient, array $timeWindows = []): array
    {
        Log::info('ProcessAlertJob: Preload payload time sources', [
            'alert_id' => $this->alert->id,
            'signal_id' => $this->alert->signal_id,
            'data_happened_at_time' => $payload['data']['happenedAtTime'] ?? null,
            'root_happened_at_time' => $payload['happenedAtTime'] ?? null,
            'event_time' => $payload['eventTime'] ?? null,
            'root_time' => $payload['time'] ?? null,
        ]);

        $context = PipelineAdapter::extractEventContext($payload);
        if (!$context) {
            Log::warning('ProcessAlertJob: No event context extracted, skipping preload', ['alert_id' => $this->alert->id]);
            return $payload;
        }

        $vehicleId = $context['vehicle_id'];
        $eventTime = $context['happened_at_time'];
        $isSafetyEvent = PipelineAdapter::isSafetyEvent($payload);

        Log::info('ProcessAlertJob: Preload using extracted context', [
            'alert_id' => $this->alert->id,
            'vehicle_id' => $vehicleId,
            'event_time_utc' => $eventTime,
        ]);

        $dbSafetyEvents = $this->getSafetyEventsFromDatabase(
            vehicleId: $vehicleId,
            eventTime: $eventTime,
            isSafetyEvent: $isSafetyEvent,
            timeWindows: $timeWindows
        );

        $preloadedData = $samsaraClient->preloadAllData(
            vehicleId: $vehicleId,
            eventTime: $eventTime,
            isSafetyEvent: $isSafetyEvent,
            timeWindows: $timeWindows,
            dbSafetyEvents: $dbSafetyEvents
        );

        if (empty($preloadedData)) {
            return $payload;
        }

        if (empty($dbSafetyEvents) && !empty($preloadedData['_api_safety_events'])) {
            $this->persistSafetyEventsFromApi($preloadedData['_api_safety_events']);
            unset($preloadedData['_api_safety_events']);
        }

        $payload['preloaded_data'] = $preloadedData;

        if (!empty($preloadedData['safety_event_detail']['driver']['id'])) {
            $payload['driver'] = $preloadedData['safety_event_detail']['driver'];
        } elseif (!empty($preloadedData['driver_assignment']['driver']['id'])) {
            $payload['driver'] = $preloadedData['driver_assignment']['driver'];
        }

        if (!empty($preloadedData['safety_event_detail']['behavior_label'])) {
            $payload['behavior_label'] = $preloadedData['safety_event_detail']['behavior_label'];
        }

        if (!empty($preloadedData['safety_event_detail']['severity'])) {
            $payload['samsara_severity'] = $preloadedData['safety_event_detail']['severity'];
        }

        if (!empty($preloadedData['safety_event_detail'])) {
            $payload['safety_event_detail'] = $preloadedData['safety_event_detail'];
        }

        return $payload;
    }

    private function getSafetyEventsFromDatabase(
        string $vehicleId,
        string $eventTime,
        bool $isSafetyEvent,
        array $timeWindows = []
    ): array {
        $eventDt = Carbon::parse($eventTime);
        $companyId = $this->alert->company_id;

        if ($isSafetyEvent) {
            $startTime = $eventDt->copy()->subMinutes(2);
            $endTime = $eventDt->copy()->addMinutes(2);
        } else {
            $beforeMinutes = $timeWindows['safety_events_before_minutes'] ?? 30;
            $afterMinutes = $timeWindows['safety_events_after_minutes'] ?? 10;
            $startTime = $eventDt->copy()->subMinutes($beforeMinutes);
            $endTime = $eventDt->copy()->addMinutes($afterMinutes);
        }

        $signals = SafetySignal::query()
            ->forCompany($companyId)
            ->forVehicle($vehicleId)
            ->inDateRange($startTime, $endTime)
            ->orderBy('occurred_at', 'desc')
            ->get();

        if ($signals->isEmpty()) {
            return [];
        }

        return $signals->map(function (SafetySignal $signal) {
            return [
                'id' => $signal->samsara_event_id,
                'asset' => ['id' => $signal->vehicle_id, 'name' => $signal->vehicle_name],
                'driver' => $signal->driver_id ? ['id' => $signal->driver_id, 'name' => $signal->driver_name] : null,
                'location' => [
                    'latitude' => $signal->latitude ? (float) $signal->latitude : null,
                    'longitude' => $signal->longitude ? (float) $signal->longitude : null,
                    'address' => $signal->address ? ['formattedAddress' => $signal->address] : [],
                ],
                'behaviorLabels' => $signal->behavior_labels ?? [],
                'contextLabels' => $signal->context_labels ?? [],
                'eventState' => $signal->event_state,
                'maxAccelerationGForce' => $signal->max_acceleration_g,
                'speedingMetadata' => $signal->speeding_metadata,
                'media' => $signal->media_urls,
                'inboxEventUrl' => $signal->inbox_event_url,
                'incidentReportUrl' => $signal->incident_report_url,
                'startMs' => $signal->occurred_at?->getTimestampMs(),
                'createdAtTime' => $signal->samsara_created_at?->toIso8601String(),
                'updatedAtTime' => $signal->samsara_updated_at?->toIso8601String(),
                '_from_database' => true,
            ];
        })->toArray();
    }

    private function persistSafetyEventsFromApi(array $apiEvents): void
    {
        $companyId = $this->alert->company_id;

        foreach ($apiEvents as $eventData) {
            $samsaraEventId = $eventData['id'] ?? null;
            if (!$samsaraEventId) {
                continue;
            }

            if (SafetySignal::where('company_id', $companyId)->where('samsara_event_id', $samsaraEventId)->exists()) {
                continue;
            }

            try {
                SafetySignal::createFromStreamEvent($companyId, $eventData);
            } catch (\Exception $e) {
                Log::warning("Failed to persist safety event from API", [
                    'alert_id' => $this->alert->id,
                    'samsara_event_id' => $samsaraEventId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function createIncidentIfNeeded(array $assessment): void
    {
        try {
            $gate = app(IncidentCreationGate::class);

            if (!$gate->shouldCreateIncident($this->alert, $assessment)) {
                return;
            }

            $incident = $gate->createFromAlert($this->alert, $assessment);

            if ($incident) {
                Log::info("Incident created for alert", [
                    'incident_id' => $incident->id,
                    'alert_id' => $this->alert->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Failed to create incident for alert", [
                'alert_id' => $this->alert->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function emitUsageEvents(array $metricsData): void
    {
        $alertId = $this->alert->id;
        $companyId = $this->alert->company_id;

        RecordUsageEventJob::dispatch(
            companyId: $companyId,
            meter: 'alerts_processed',
            qty: 1,
            idempotencyKey: "{$companyId}:alerts_processed:{$alertId}",
        );

        $tokens = $metricsData['ai_tokens'] ?? null;
        if ($tokens) {
            RecordUsageEventJob::dispatch(
                companyId: $companyId,
                meter: 'ai_tokens',
                qty: $tokens,
                idempotencyKey: "{$companyId}:ai_tokens:{$alertId}",
                dimensions: ['cost_estimate' => $metricsData['ai_cost_estimate'] ?? null],
            );
        }
    }

    private function initializeAttentionEngine(): void
    {
        try {
            $company = $this->alert->company;
            if (!$company) {
                return;
            }

            if (!\Laravel\Pennant\Feature::for($company)->active('attention-engine-v1')) {
                return;
            }

            app(AttentionEngine::class)->initializeAttention($this->alert->fresh());
        } catch (\Exception $e) {
            Log::warning('AttentionEngine initialization failed (non-blocking)', [
                'alert_id' => $this->alert->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
