<?php

namespace App\Jobs;

use App\Jobs\Traits\PersistsEvidenceImages;
use App\Models\Alert;
use App\Models\AlertAi;
use App\Models\AlertMetrics;
use App\Pulse\Recorders\AiServiceRecorder;
use App\Pulse\Recorders\AlertProcessingRecorder;
use App\Services\AttentionEngine;
use App\Services\ContactResolver;
use App\Services\DomainEventEmitter;
use App\Services\MonitorMatrixOverride;
use App\Samsara\Client\PipelineAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RevalidateAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, PersistsEvidenceImages;
    use Traits\LogsWithTenantContext;

    public $tries = 2;
    public $timeout = 420;

    public function __construct(
        public Alert $alert
    ) {
        $this->onQueue('samsara-revalidation');
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $context = array_merge([
            'alert_id' => $this->alert->id,
            'job_attempt' => $this->attempts(),
        ], $context);

        Log::channel('revalidation')->{$level}("[REVALIDATION] {$message}", $context);
        Log::{$level}("[REVALIDATION] {$message}", $context);
    }

    public function handle(ContactResolver $contactResolver, MonitorMatrixOverride $monitorMatrixOverride): void
    {
        $jobStartTime = microtime(true);

        $this->alert->refresh();
        $this->alert->load(['signal', 'ai']);

        $company = $this->alert->company;
        $signal = $this->alert->signal;
        $ai = $this->alert->ai;

        $this->setLogContext($company);

        $this->log('info', '========== REVALIDATION JOB STARTED ==========', [
            'signal_id' => $signal?->id,
            'samsara_event_id' => $signal?->samsara_event_id,
            'vehicle_name' => $signal?->vehicle_name,
            'current_ai_status' => $this->alert->ai_status,
            'investigation_count' => $ai?->investigation_count,
        ]);

        DomainEventEmitter::emit(
            companyId: $this->alert->company_id,
            entityType: 'alert',
            entityId: (string) $this->alert->id,
            eventType: 'alert.revalidation_started',
            payload: [
                'investigation_count' => $ai?->investigation_count,
                'attempt' => $this->attempts(),
            ],
            correlationId: (string) $this->alert->id,
        );

        if ($this->alert->ai_status !== Alert::STATUS_INVESTIGATING) {
            $this->log('warning', 'Alert no longer in INVESTIGATING status - SKIPPING', [
                'actual_status' => $this->alert->ai_status,
            ]);
            return;
        }

        $maxInvestigations = Alert::getMaxInvestigations($company);
        $currentCount = $ai?->investigation_count ?? 0;

        if ($currentCount >= $maxInvestigations) {
            $this->log('warning', 'Max investigations limit reached', [
                'investigation_count' => $currentCount,
                'max_investigations' => $maxInvestigations,
            ]);

            $this->alert->markAsCompleted(
                assessment: array_merge($ai?->ai_assessment ?? [], [
                    'verdict' => 'needs_review',
                    'likelihood' => 'medium',
                    'confidence' => 0.5,
                    'reasoning' => 'Máximo de revalidaciones alcanzado. Requiere revisión humana.',
                    'risk_escalation' => 'warn',
                    'requires_monitoring' => false,
                ]),
                humanMessage: 'Este evento requiere revisión manual después de ' . $maxInvestigations . ' análisis automáticos.',
                alertContext: $ai?->alert_context,
                notificationDecision: $this->alert->notification_decision_payload,
                execution: $ai?->ai_actions
            );

            return;
        }

        try {
            $aiConfig = $company->getAiConfig();
            $timeWindows = $aiConfig['investigation_windows'] ?? [];

            $contacts = $contactResolver->resolveForAlert($this->alert);
            $contactPayload = $contactResolver->formatForPayload($contacts);

            $rawPayload = $signal?->raw_payload ?? [];
            $enrichedPayload = array_merge($rawPayload, $contactPayload);
            $enrichedPayload = $this->reloadSamsaraDataForRevalidation($enrichedPayload, $timeWindows);

            $aiServiceUrl = config('services.ai_engine.url');

            $revalidationContext = [
                'is_revalidation' => true,
                'original_event_time' => $this->alert->occurred_at?->toIso8601String(),
                'first_investigation_time' => $this->alert->created_at->toIso8601String(),
                'last_investigation_time' => $ai?->last_investigation_at?->toIso8601String(),
                'current_revalidation_time' => now()->toIso8601String(),
                'investigation_count' => $currentCount,
                'previous_assessment' => $ai?->ai_assessment,
                'previous_alert_context' => $ai?->alert_context,
                'investigation_history' => $ai?->investigation_history ?? [],
            ];

            $enrichedPayload['company_id'] = $this->alert->company_id;
            $enrichedPayload['company_config'] = $aiConfig;

            $requestStartTime = microtime(true);

            $response = Http::timeout(300)
                ->withHeaders([
                    'traceparent' => $this->getTraceparent(),
                    'X-Trace-ID' => $this->getTraceId(),
                ])
                ->post("{$aiServiceUrl}/alerts/revalidate", [
                    'event_id' => $this->alert->id,
                    'payload' => $enrichedPayload,
                    'context' => $revalidationContext,
                ]);

            $requestDuration = round((microtime(true) - $requestStartTime) * 1000, 2);

            AiServiceRecorder::recordAiServiceCall(
                endpoint: '/alerts/revalidate',
                success: $response->successful(),
                durationMs: (int) $requestDuration,
                statusCode: $response->status()
            );

            if ($response->status() === 503) {
                throw new \Exception("AI service at capacity.");
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

            $alertContext = $result['alert_context'] ?? $ai?->alert_context;
            $humanMessage = $result['human_message'] ?? 'Revalidación completada';
            $notificationDecision = $result['notification_decision'] ?? null;
            $execution = $result['execution'] ?? $ai?->ai_actions;
            $cameraAnalysis = $result['camera_analysis'] ?? null;

            [$execution, $cameraAnalysis] = $this->persistEvidenceImages($execution, $cameraAnalysis);
            if ($cameraAnalysis) {
                $execution['camera_analysis'] = $cameraAnalysis;
            }

            $requiresMonitoring = $assessment['requires_monitoring'] ?? false;
            $nextCheckMinutes = $assessment['next_check_minutes'] ?? 30;
            $monitoringReason = $assessment['monitoring_reason'] ?? null;

            if ($requiresMonitoring) {
                DB::transaction(function () use ($assessment, $humanMessage, $nextCheckMinutes, $alertContext, $notificationDecision, $execution) {
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
                });

                $this->alert->addInvestigationRecord(
                    reason: $monitoringReason ?? 'Requiere más tiempo para contexto'
                );

                DomainEventEmitter::emit(
                    companyId: $this->alert->company_id,
                    entityType: 'alert',
                    entityId: (string) $this->alert->id,
                    eventType: 'alert.revalidation_completed',
                    payload: [
                        'verdict' => $assessment['verdict'] ?? null,
                        'requires_monitoring' => true,
                        'next_check_minutes' => $nextCheckMinutes,
                    ],
                    correlationId: (string) $this->alert->id,
                );

                $this->emitUsageEvents($execution);

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
                self::dispatch($this->alert)
                    ->delay($scheduledAt)
                    ->onQueue('samsara-revalidation');

                $jobDuration = round((microtime(true) - $jobStartTime) * 1000, 2);

                $this->log('info', '========== REVALIDATION COMPLETED - CONTINUES MONITORING ==========', [
                    'verdict' => $assessment['verdict'] ?? 'unknown',
                    'next_revalidation_at' => $scheduledAt->toIso8601String(),
                    'duration_ms' => $jobDuration,
                ]);

                AlertProcessingRecorder::recordAlertProcessing(
                    alert: $this->alert,
                    durationMs: (int) $jobDuration,
                    status: 'investigating'
                );
            } else {
                DB::transaction(function () use ($assessment, $humanMessage, $alertContext, $notificationDecision, $execution) {
                    $this->alert->markAsCompleted(
                        assessment: $assessment,
                        humanMessage: $humanMessage,
                        alertContext: $alertContext,
                        notificationDecision: $notificationDecision,
                        execution: $execution
                    );

                    $this->alert->saveRecommendedActions($assessment['recommended_actions'] ?? []);
                    $this->alert->saveInvestigationSteps($alertContext['investigation_plan'] ?? []);
                });

                DomainEventEmitter::emit(
                    companyId: $this->alert->company_id,
                    entityType: 'alert',
                    entityId: (string) $this->alert->id,
                    eventType: 'alert.revalidation_completed',
                    payload: [
                        'verdict' => $assessment['verdict'] ?? null,
                        'requires_monitoring' => false,
                    ],
                    correlationId: (string) $this->alert->id,
                );

                $this->emitUsageEvents($execution);

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

                $this->log('info', '========== REVALIDATION COMPLETED - INVESTIGATION FINISHED ==========', [
                    'final_verdict' => $assessment['verdict'] ?? 'unknown',
                    'duration_ms' => $jobDuration,
                ]);

                AlertProcessingRecorder::recordAlertProcessing(
                    alert: $this->alert,
                    durationMs: (int) $jobDuration,
                    status: 'completed'
                );
            }

        } catch (\Exception $e) {
            $jobDuration = round((microtime(true) - $jobStartTime) * 1000, 2);

            $this->log('error', 'REVALIDATION JOB FAILED', [
                'error_message' => $e->getMessage(),
                'will_retry' => $this->attempts() < $this->tries,
                'duration_ms' => $jobDuration,
            ]);

            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->setLogContext($this->alert->company);

        $this->log('error', '========== REVALIDATION JOB FAILED PERMANENTLY ==========', [
            'error_message' => $exception->getMessage(),
            'total_attempts' => $this->attempts(),
        ]);
    }

    private function persistRevalidationWindow(array $reloadedData): void
    {
        $ai = $this->alert->ai;
        if (!$ai) {
            return;
        }

        $metadata = $reloadedData['_metadata'] ?? [];
        $queryWindow = $metadata['query_window'] ?? [];

        $windowSummary = [
            'investigation_number' => ($ai->investigation_count ?? 0) + 1,
            'queried_at' => now()->toIso8601String(),
            'time_window' => [
                'start' => $queryWindow['start'] ?? null,
                'end' => $queryWindow['end'] ?? null,
                'minutes_covered' => $queryWindow['minutes_covered'] ?? 0,
            ],
            'findings' => [
                'new_safety_events' => $reloadedData['safety_events_since_last_check']['total_events'] ?? 0,
                'new_camera_items' => $reloadedData['camera_media_since_last_check']['total_items'] ?? 0,
            ],
        ];

        $history = $ai->investigation_history ?? [];
        $history[] = $windowSummary;
        $ai->update(['investigation_history' => $history]);
    }

    private function reloadSamsaraDataForRevalidation(array $payload, array $timeWindows = []): array
    {
        $company = $this->alert->company;
        if (!$company) {
            return $payload;
        }

        $samsaraApiKey = $company->getSamsaraApiKey();
        if (empty($samsaraApiKey)) {
            return $payload;
        }

        $context = PipelineAdapter::extractEventContext($payload);
        if (!$context) {
            return $payload;
        }

        $vehicleId = $context['vehicle_id'];
        $originalEventTime = $context['happened_at_time'];
        $ai = $this->alert->ai;
        $lastInvestigationTime = $ai?->last_investigation_at?->toIso8601String()
            ?? $this->alert->created_at->toIso8601String();
        $isSafetyEvent = PipelineAdapter::isSafetyEvent($payload);

        try {
            $samsaraClient = new PipelineAdapter($samsaraApiKey);
            $reloadedData = $samsaraClient->reloadDataForRevalidation(
                vehicleId: $vehicleId,
                originalEventTime: $originalEventTime,
                lastInvestigationTime: $lastInvestigationTime,
                isSafetyEvent: $isSafetyEvent,
                timeWindows: $timeWindows
            );

            if (empty($reloadedData)) {
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
            $payload['revalidation_windows_history'] = $this->alert->ai?->fresh()->investigation_history ?? [];

            return $payload;

        } catch (\Exception $e) {
            $this->log('error', 'Failed to reload Samsara data', ['error' => $e->getMessage()]);
            return $payload;
        }
    }

    private function emitUsageEvents(?array $execution): void
    {
        $alertId = $this->alert->id;
        $companyId = $this->alert->company_id;
        $investigationCount = $this->alert->ai?->investigation_count ?? 0;

        RecordUsageEventJob::dispatch(
            companyId: $companyId,
            meter: 'alerts_revalidated',
            qty: 1,
            idempotencyKey: "{$companyId}:alerts_revalidated:{$alertId}:{$investigationCount}",
        );

        $tokens = $execution['total_tokens'] ?? null;
        if ($tokens) {
            RecordUsageEventJob::dispatch(
                companyId: $companyId,
                meter: 'ai_tokens',
                qty: (int) $tokens,
                idempotencyKey: "{$companyId}:ai_tokens:{$alertId}:reval:{$investigationCount}",
                dimensions: ['cost_estimate' => $execution['cost_estimate'] ?? null],
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
