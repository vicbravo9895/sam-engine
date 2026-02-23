<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessAlertJob;
use App\Jobs\RevalidateAlertJob;
use App\Jobs\SendNotificationJob;
use App\Models\Alert;
use App\Models\AlertAi;
use App\Models\AlertMetrics;
use App\Samsara\Client\PipelineAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;
use Tests\Traits\MocksExternalServices;

class ProcessAlertJobTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline, MocksExternalServices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    private function runJob(ProcessAlertJob $job): void
    {
        app()->call([$job, 'handle']);
    }

    public function test_processes_alert_through_ai_pipeline(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class]);
        $this->mockAiServiceSuccess();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->mockPipelineAdapter();

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
        $this->assertEquals('confirmed_violation', $alert->verdict);
        $this->assertNotNull($alert->ai_message);
    }

    public function test_skips_already_completed_alert(): void
    {
        Http::fake();

        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Http::assertNothingSent();
    }

    public function test_skips_alert_without_samsara_key(): void
    {
        $this->company->update(['samsara_api_key' => null]);
        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->expectException(\Exception::class);

        $this->runJob(new ProcessAlertJob($alert));
    }

    public function test_marks_as_investigating_when_requires_monitoring(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class]);
        $this->mockAiServiceMonitoring(15);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_INVESTIGATING, $alert->ai_status);
    }

    public function test_marks_as_completed_when_no_monitoring_needed(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
    }

    public function test_dispatches_send_notification_job(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class]);
        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(SendNotificationJob::class);
    }

    public function test_schedules_revalidation_for_monitoring(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class]);
        $this->mockAiServiceMonitoring(15);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(RevalidateAlertJob::class);
    }

    public function test_saves_recommended_actions_to_table(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $actions = $alert->getRecommendedActionsArray();
        $this->assertNotEmpty($actions);
    }

    public function test_saves_investigation_steps_to_table(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $steps = $alert->getInvestigationStepsArray();
        $this->assertNotEmpty($steps);
    }

    public function test_handles_ai_service_error_gracefully(): void
    {
        $this->mockAiServiceFailure(500);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->expectException(\Exception::class);

        $this->runJob(new ProcessAlertJob($alert));
    }

    public function test_handles_ai_service_503_retry(): void
    {
        Http::fake([
            'api.samsara.com/*' => Http::response(['data' => []], 200),
            '*/alerts/ingest' => Http::response(['error' => 'At capacity', 'stats' => ['active_requests' => 10]], 503),
        ]);

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('AI service at capacity');

        $this->runJob(new ProcessAlertJob($alert));
    }

    public function test_records_pipeline_metrics(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $this->assertDatabaseHas('alert_metrics', [
            'alert_id' => $alert->id,
        ]);
    }

    public function test_emits_usage_events(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(\App\Jobs\RecordUsageEventJob::class);
    }

    private function mockPipelineAdapter(): void
    {
        // PipelineAdapter is instantiated via `new` (not DI), so we can't
        // container-mock it. Instead, the Samsara API calls are already
        // covered by Http::fake() patterns set in mockAiService* helpers.
    }

    protected function mockAiServiceWithSamsara(array $aiResponse, int $status = 200): void
    {
        Http::fake([
            'api.samsara.com/*' => Http::response(['data' => []], 200),
            '*/alerts/ingest' => Http::response($aiResponse, $status),
            '*/alerts/revalidate' => Http::response($aiResponse, $status),
        ]);
    }

    // ─── Skip already-investigating alerts ──────────────────────────

    public function test_skips_already_investigating_alert(): void
    {
        Http::fake();

        ['alert' => $alert] = $this->createInvestigatingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Http::assertNothingSent();
    }

    // ─── AI service error responses ─────────────────────────────────

    public function test_handles_ai_pipeline_error_status(): void
    {
        $this->mockAiServiceWithSamsara([
            'status' => 'error',
            'error' => 'Pipeline execution failed',
        ]);

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('AI service pipeline error');

        $this->runJob(new ProcessAlertJob($alert));
    }

    public function test_handles_missing_verdict_in_assessment(): void
    {
        $this->mockAiServiceWithSamsara([
            'success' => true,
            'assessment' => ['likelihood' => 'high'],
        ]);

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('missing verdict');

        $this->runJob(new ProcessAlertJob($alert));
    }

    public function test_handles_empty_assessment(): void
    {
        $this->mockAiServiceWithSamsara([
            'success' => true,
            'assessment' => [],
        ]);

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('missing verdict');

        $this->runJob(new ProcessAlertJob($alert));
    }

    // ─── Pipeline metrics recording ─────────────────────────────────

    public function test_records_pipeline_metrics_latency(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $metrics = AlertMetrics::where('alert_id', $alert->id)->first();
        $this->assertNotNull($metrics);
        $this->assertNotNull($metrics->pipeline_latency_ms);
        $this->assertGreaterThanOrEqual(0, $metrics->pipeline_latency_ms);
    }

    public function test_records_token_usage_in_metrics(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceSuccess([
            'execution' => [
                'total_tokens' => 2500,
                'cost_estimate' => 0.0085,
                'agents_executed' => ['triage', 'investigator', 'final', 'notification'],
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $metrics = AlertMetrics::where('alert_id', $alert->id)->first();
        $this->assertEquals(2500, $metrics->ai_tokens);
        $this->assertEquals(0.0085, (float) $metrics->ai_cost_estimate);
    }

    public function test_handles_missing_execution_tokens_gracefully(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceSuccess([
            'execution' => [
                'agents_executed' => ['triage', 'investigator'],
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $metrics = AlertMetrics::where('alert_id', $alert->id)->first();
        $this->assertNull($metrics->ai_tokens);
    }

    // ─── Notification decision paths ────────────────────────────────

    public function test_does_not_dispatch_notification_when_should_notify_false(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess([
            'notification_decision' => [
                'should_notify' => false,
                'escalation_level' => 'none',
                'reason' => 'Low risk event.',
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    public function test_handles_null_notification_decision(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess([
            'notification_decision' => null,
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    // ─── Monitoring path details ────────────────────────────────────

    public function test_monitoring_saves_investigation_record(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class]);
        $this->mockAiServiceMonitoring(10);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $ai = AlertAi::where('alert_id', $alert->id)->first();
        $this->assertNotNull($ai);
        $this->assertNotEmpty($ai->investigation_history);
    }

    public function test_monitoring_dispatches_notification_when_should_notify(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class]);
        $this->mockAiServiceSuccess([
            'assessment' => [
                'verdict' => 'uncertain',
                'likelihood' => 'medium',
                'confidence' => 0.55,
                'reasoning' => 'Requires further monitoring.',
                'requires_monitoring' => true,
                'monitoring_reason' => 'Insufficient data.',
                'next_check_minutes' => 15,
                'risk_escalation' => 'warn',
                'recommended_actions' => ['Monitor closely'],
            ],
            'notification_decision' => [
                'should_notify' => true,
                'escalation_level' => 'low',
                'message_text' => 'Monitoring alert.',
                'channels_to_use' => ['sms'],
                'recipients' => [['recipient_type' => 'operator', 'phone' => '+521234567890', 'priority' => 1]],
                'reason' => 'Monitoring notification.',
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(SendNotificationJob::class);
        Bus::assertDispatched(RevalidateAlertJob::class);
    }

    public function test_monitoring_records_metrics(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceMonitoring(20);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $metrics = AlertMetrics::where('alert_id', $alert->id)->first();
        $this->assertNotNull($metrics);
        $this->assertNotNull($metrics->pipeline_latency_ms);
    }

    // ─── AI human message and alert context ─────────────────────────

    public function test_stores_ai_human_message(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess([
            'human_message' => 'Se detectó un evento crítico de seguridad.',
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals('Se detectó un evento crítico de seguridad.', $alert->ai_message);
    }

    public function test_uses_default_human_message_when_missing(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess([
            'human_message' => null,
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals('Procesamiento completado', $alert->ai_message);
    }

    // ─── Alert description update from safety event ─────────────────

    public function test_updates_generic_description_from_behavior_name(): void
    {
        Bus::fake([SendNotificationJob::class]);

        $this->mockAiServiceSuccess();

        ['alert' => $alert, 'signal' => $signal] = $this->createPendingAlert($this->company);
        $signal->update([
            'event_description' => 'A safety event occurred',
            'raw_payload' => array_merge($signal->raw_payload ?? [], [
                'happenedAtTime' => now()->toIso8601String(),
                'vehicle' => ['id' => $signal->vehicle_id, 'name' => $signal->vehicle_name],
            ]),
        ]);

        Http::fake([
            'api.samsara.com/*' => Http::response([
                'data' => [
                    [
                        'id' => 'safety-1',
                        'asset' => ['id' => $signal->vehicle_id, 'name' => $signal->vehicle_name],
                        'behaviorLabels' => [['name' => 'Hard Braking']],
                        'behavior_name' => 'Hard Braking',
                    ],
                ],
            ], 200),
            '*/alerts/ingest' => Http::response(array_merge(
                $this->getSuccessAiResponse(),
                []
            ), 200),
        ]);

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
    }

    // ─── Incident creation path ─────────────────────────────────────

    public function test_creates_incident_for_critical_alerts(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess([
            'assessment' => [
                'verdict' => 'real_panic',
                'likelihood' => 'high',
                'confidence' => 0.98,
                'reasoning' => 'Confirmed panic.',
                'requires_monitoring' => false,
                'risk_escalation' => 'emergency',
                'recommended_actions' => ['Call driver immediately'],
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createFullAlert(
            $this->company,
            ['event_type' => 'AlertIncident', 'event_description' => 'Panic button pressed'],
            ['ai_status' => Alert::STATUS_PENDING, 'severity' => Alert::SEVERITY_CRITICAL]
        );

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
    }

    // ─── Failed job handler ─────────────────────────────────────────

    public function test_failed_method_marks_alert_as_failed(): void
    {
        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $job = new ProcessAlertJob($alert);
        $job->failed(new \Exception('Permanent failure'));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_FAILED, $alert->ai_status);
    }

    public function test_failed_method_logs_activity(): void
    {
        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $job = new ProcessAlertJob($alert);
        $job->failed(new \Exception('Something went wrong'));

        $this->assertDatabaseHas('alert_activities', [
            'alert_id' => $alert->id,
            'action' => \App\Models\AlertActivity::ACTION_AI_FAILED,
        ]);
    }

    public function test_failed_method_logs_critical_for_critical_severity(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        ['alert' => $alert] = $this->createFullAlert(
            $this->company,
            [],
            ['ai_status' => Alert::STATUS_PENDING, 'severity' => Alert::SEVERITY_CRITICAL]
        );

        $job = new ProcessAlertJob($alert);
        $job->failed(new \Exception('Critical alert failed'));

        \Illuminate\Support\Facades\Log::shouldHaveReceived('critical')
            ->withArgs(fn ($msg) => str_contains($msg, 'CRITICAL ALERT PROCESSING FAILED'))
            ->once();
    }

    public function test_failed_method_does_not_log_critical_for_info_severity(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        ['alert' => $alert] = $this->createFullAlert(
            $this->company,
            [],
            ['ai_status' => Alert::STATUS_PENDING, 'severity' => Alert::SEVERITY_INFO]
        );

        $job = new ProcessAlertJob($alert);
        $job->failed(new \Exception('Info alert failed'));

        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('critical');
    }

    // ─── Usage events ───────────────────────────────────────────────

    public function test_emits_token_usage_event_when_tokens_present(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceSuccess([
            'execution' => [
                'total_tokens' => 3000,
                'cost_estimate' => 0.01,
                'agents_executed' => ['triage', 'investigator', 'final', 'notification'],
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(\App\Jobs\RecordUsageEventJob::class, function ($job) {
            return $job->meter === 'ai_tokens';
        });
    }

    public function test_does_not_emit_token_event_without_tokens(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceSuccess([
            'execution' => ['agents_executed' => ['triage']],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(\App\Jobs\RecordUsageEventJob::class, function ($job) {
            return $job->meter === 'alerts_processed';
        });
        Bus::assertNotDispatched(\App\Jobs\RecordUsageEventJob::class, function ($job) {
            return $job->meter === 'ai_tokens';
        });
    }

    // ─── Monitoring with custom next_check_minutes ──────────────────

    public function test_monitoring_uses_custom_next_check_minutes(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class]);
        $this->mockAiServiceMonitoring(30);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $ai = AlertAi::where('alert_id', $alert->id)->first();
        $this->assertEquals(30, $ai->next_check_minutes);
    }

    // ─── Different verdict types ────────────────────────────────────

    public function test_completes_with_false_positive_verdict(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess([
            'assessment' => [
                'verdict' => 'likely_false_positive',
                'likelihood' => 'low',
                'confidence' => 0.88,
                'reasoning' => 'Sensor malfunction likely.',
                'requires_monitoring' => false,
                'risk_escalation' => 'monitor',
                'recommended_actions' => ['Check sensor calibration'],
            ],
            'notification_decision' => [
                'should_notify' => false,
                'escalation_level' => 'none',
                'reason' => 'False positive, no notification needed.',
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
        $this->assertEquals('likely_false_positive', $alert->verdict);
    }

    public function test_completes_with_no_action_needed_verdict(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess([
            'assessment' => [
                'verdict' => 'no_action_needed',
                'likelihood' => 'low',
                'confidence' => 0.95,
                'reasoning' => 'Normal driving behavior.',
                'requires_monitoring' => false,
                'risk_escalation' => 'monitor',
                'recommended_actions' => [],
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
        $this->assertEquals('no_action_needed', $alert->verdict);
    }

    // ─── Company without samsara key (different from null) ──────────

    public function test_fails_when_company_has_empty_samsara_key(): void
    {
        $this->company->update(['samsara_api_key' => '']);
        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not have Samsara API key');

        $this->runJob(new ProcessAlertJob($alert));
    }

    // ─── Camera analysis in response ────────────────────────────────

    public function test_handles_camera_analysis_in_response(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\PersistMediaAssetJob::class]);
        $this->mockAiServiceSuccess([
            'camera_analysis' => [
                'has_media' => true,
                'media_urls' => ['https://samsara.example.com/image1.jpg'],
                'analysis' => 'Driver appears distracted.',
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
    }

    // ─── Recommended actions empty ──────────────────────────────────

    public function test_handles_empty_recommended_actions(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess([
            'assessment' => [
                'verdict' => 'no_action_needed',
                'likelihood' => 'low',
                'confidence' => 0.90,
                'reasoning' => 'All clear.',
                'requires_monitoring' => false,
                'risk_escalation' => 'monitor',
                'recommended_actions' => [],
            ],
            'alert_context' => [
                'alert_kind' => 'safety',
                'triage_notes' => 'Normal.',
                'investigation_plan' => [],
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $actions = $alert->getRecommendedActionsArray();
        $this->assertEmpty($actions);
    }

    // ─── Evidence image persistence (PersistsEvidenceImages trait) ────

    public function test_dispatches_persist_media_asset_job_for_camera_analysis_urls(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\PersistMediaAssetJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceSuccess([
            'camera_analysis' => [
                'has_media' => true,
                'media_urls' => [
                    'https://samsara.example.com/image1.jpg',
                    'https://samsara.example.com/image2.jpg',
                ],
                'analysis' => 'Driver appears distracted in both frames.',
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(\App\Jobs\PersistMediaAssetJob::class, 2);
        $this->assertDatabaseHas('media_assets', [
            'assetable_id' => $alert->id,
            'assetable_type' => Alert::class,
            'category' => \App\Models\MediaAsset::CATEGORY_EVIDENCE,
            'status' => \App\Models\MediaAsset::STATUS_PENDING,
            'source_url' => 'https://samsara.example.com/image1.jpg',
        ]);
    }

    public function test_dispatches_persist_media_asset_for_execution_tool_urls(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\PersistMediaAssetJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceSuccess([
            'execution' => [
                'total_tokens' => 1200,
                'cost_estimate' => 0.003,
                'agents_executed' => ['triage', 'investigator'],
                'agents' => [
                    [
                        'name' => 'investigator',
                        'tools' => [
                            [
                                'name' => 'analyze_camera',
                                'media_urls' => ['https://cdn.samsara.com/tool-image.jpg'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(\App\Jobs\PersistMediaAssetJob::class, 1);
        $this->assertDatabaseHas('media_assets', [
            'assetable_id' => $alert->id,
            'source_url' => 'https://cdn.samsara.com/tool-image.jpg',
        ]);
    }

    public function test_skips_local_urls_in_evidence_persistence(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\PersistMediaAssetJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceSuccess([
            'camera_analysis' => [
                'has_media' => true,
                'media_urls' => ['/storage/evidence/already-local.jpg'],
                'analysis' => 'Already stored.',
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertNotDispatched(\App\Jobs\PersistMediaAssetJob::class);
    }

    public function test_camera_analysis_attached_to_execution_in_ai_data(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\PersistMediaAssetJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceSuccess([
            'camera_analysis' => [
                'has_media' => true,
                'media_urls' => ['https://samsara.example.com/cam.jpg'],
                'analysis' => 'Distracted driving detected.',
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $ai = AlertAi::where('alert_id', $alert->id)->first();
        $this->assertNotNull($ai);
        $actions = $ai->ai_actions ?? [];
        $this->assertArrayHasKey('camera_analysis', $actions);
        $this->assertEquals('Distracted driving detected.', $actions['camera_analysis']['analysis']);
    }

    // ─── MonitorMatrixOverride path ─────────────────────────────────

    public function test_monitor_matrix_override_dispatches_notification_when_should_notify_false(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class, \App\Jobs\RecordUsageEventJob::class]);

        $settings = $this->company->settings ?? [];
        $settings['ai_config'] = array_merge($settings['ai_config'] ?? [], [
            'escalation_matrix' => [
                'monitor' => [
                    'channels' => ['sms'],
                    'recipients' => ['monitoring'],
                ],
            ],
        ]);
        $this->company->update(['settings' => $settings]);

        \App\Models\Contact::factory()->monitoringTeam()->create([
            'company_id' => $this->company->id,
            'phone' => '+525551234567',
        ]);

        $this->mockAiServiceSuccess([
            'assessment' => [
                'verdict' => 'uncertain',
                'likelihood' => 'medium',
                'confidence' => 0.55,
                'reasoning' => 'Requires monitoring.',
                'requires_monitoring' => true,
                'monitoring_reason' => 'Need more data.',
                'next_check_minutes' => 15,
                'risk_escalation' => 'monitor',
            ],
            'notification_decision' => [
                'should_notify' => false,
                'escalation_level' => 'none',
                'reason' => 'AI decided no notification needed.',
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(SendNotificationJob::class);
    }

    public function test_completed_path_monitor_matrix_override(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);

        $settings = $this->company->settings ?? [];
        $settings['ai_config'] = array_merge($settings['ai_config'] ?? [], [
            'escalation_matrix' => [
                'monitor' => [
                    'channels' => ['whatsapp'],
                    'recipients' => ['monitoring'],
                ],
            ],
        ]);
        $this->company->update(['settings' => $settings]);

        \App\Models\Contact::factory()->monitoringTeam()->create([
            'company_id' => $this->company->id,
            'phone' => '+525559876543',
        ]);

        $this->mockAiServiceSuccess([
            'notification_decision' => [
                'should_notify' => false,
                'escalation_level' => 'none',
                'reason' => 'Low risk, no notification.',
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(SendNotificationJob::class);
    }

    // ─── Domain event emission paths ─────────────────────────────────

    public function test_emits_processing_started_domain_event(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\EmitDomainEventJob::class, \App\Jobs\RecordUsageEventJob::class]);

        \Laravel\Pennant\Feature::define('ledger-v1', fn ($scope) => true);

        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(\App\Jobs\EmitDomainEventJob::class, function ($job) {
            return $job->eventType === 'alert.processing_started';
        });
    }

    public function test_emits_completed_domain_event(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\EmitDomainEventJob::class, \App\Jobs\RecordUsageEventJob::class]);

        \Laravel\Pennant\Feature::define('ledger-v1', fn ($scope) => true);

        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(\App\Jobs\EmitDomainEventJob::class, function ($job) {
            return $job->eventType === 'alert.completed';
        });
    }

    public function test_emits_investigating_domain_event(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class, \App\Jobs\EmitDomainEventJob::class, \App\Jobs\RecordUsageEventJob::class]);

        \Laravel\Pennant\Feature::define('ledger-v1', fn ($scope) => true);

        $this->mockAiServiceMonitoring(10);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(\App\Jobs\EmitDomainEventJob::class, function ($job) {
            return $job->eventType === 'alert.investigating';
        });
    }

    // ─── Attention engine integration ────────────────────────────────

    public function test_initializes_attention_engine_when_feature_active(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class, \App\Jobs\EmitDomainEventJob::class]);

        \Laravel\Pennant\Feature::define('attention-engine-v1', fn ($scope) => true);

        $this->mockAiServiceSuccess([
            'assessment' => [
                'verdict' => 'confirmed_violation',
                'likelihood' => 'high',
                'confidence' => 0.95,
                'reasoning' => 'Critical violation.',
                'requires_monitoring' => false,
                'risk_escalation' => 'emergency',
                'recommended_actions' => ['Contact driver immediately'],
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createFullAlert(
            $this->company,
            [],
            ['ai_status' => Alert::STATUS_PENDING, 'severity' => Alert::SEVERITY_CRITICAL]
        );

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
        $this->assertEquals(Alert::ATTENTION_NEEDS_ATTENTION, $alert->attention_state);
    }

    public function test_attention_engine_skipped_when_feature_inactive(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);

        \Laravel\Pennant\Feature::define('attention-engine-v1', fn ($scope) => false);

        $this->mockAiServiceSuccess([
            'assessment' => [
                'verdict' => 'confirmed_violation',
                'likelihood' => 'high',
                'confidence' => 0.95,
                'reasoning' => 'Critical violation.',
                'requires_monitoring' => false,
                'risk_escalation' => 'emergency',
                'recommended_actions' => ['Contact driver immediately'],
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createFullAlert(
            $this->company,
            [],
            ['ai_status' => Alert::STATUS_PENDING, 'severity' => Alert::SEVERITY_CRITICAL]
        );

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertNull($alert->attention_state);
    }

    public function test_attention_engine_failure_does_not_block_job(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);

        \Laravel\Pennant\Feature::define('attention-engine-v1', fn ($scope) => true);

        $mock = $this->mock(\App\Services\AttentionEngine::class);
        $mock->shouldReceive('initializeAttention')
            ->once()
            ->andThrow(new \RuntimeException('AttentionEngine blew up'));

        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
    }

    // ─── Usage event emission details ─────────────────────────────────

    public function test_usage_event_includes_correct_idempotency_key(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(\App\Jobs\RecordUsageEventJob::class, function ($job) use ($alert) {
            return $job->meter === 'alerts_processed'
                && $job->idempotencyKey === "{$this->company->id}:alerts_processed:{$alert->id}";
        });
    }

    public function test_token_usage_event_includes_dimensions(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceSuccess([
            'execution' => [
                'total_tokens' => 5000,
                'cost_estimate' => 0.015,
                'agents_executed' => ['triage', 'investigator', 'final', 'notification'],
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(\App\Jobs\RecordUsageEventJob::class, function ($job) {
            return $job->meter === 'ai_tokens'
                && $job->qty === 5000
                && ($job->dimensions['cost_estimate'] ?? null) === 0.015;
        });
    }

    public function test_monitoring_path_also_emits_usage_events(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceMonitoring(10);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Bus::assertDispatched(\App\Jobs\RecordUsageEventJob::class, function ($job) {
            return $job->meter === 'alerts_processed';
        });
    }

    // ─── Behavior label translation (updateGenericDescription) ───────

    public function test_translates_known_behavior_name_to_spanish(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);

        $eventTime = now()->toIso8601String();
        ['alert' => $alert, 'signal' => $signal] = $this->createPendingAlert($this->company);
        $signal->update([
            'event_description' => 'A safety event occurred',
            'raw_payload' => [
                'eventType' => 'safetyEvent',
                'happenedAtTime' => $eventTime,
                'vehicle' => ['id' => $signal->vehicle_id, 'name' => $signal->vehicle_name],
            ],
        ]);
        $alert->update(['event_description' => 'A safety event occurred']);

        Http::fake([
            'api.samsara.com/*' => Http::response([
                'data' => [
                    [
                        'id' => 'safety-evt-1',
                        'asset' => ['id' => $signal->vehicle_id, 'name' => $signal->vehicle_name],
                        'behaviorLabels' => [['name' => 'Distracted Driving']],
                        'startMs' => (int) (now()->getTimestampMs()),
                        'createdAtTime' => $eventTime,
                    ],
                ],
            ], 200),
            '*/alerts/ingest' => Http::response($this->getSuccessAiResponse(), 200),
        ]);

        $this->runJob(new ProcessAlertJob($alert));

        $signal->refresh();
        $alert->refresh();
        $this->assertEquals('Conducción distraída', $signal->event_description);
        $this->assertEquals('Conducción distraída', $alert->event_description);
    }

    public function test_preserves_non_generic_description(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);

        $eventTime = now()->toIso8601String();
        ['alert' => $alert, 'signal' => $signal] = $this->createPendingAlert($this->company);
        $signal->update([
            'event_description' => 'Frenado brusco en zona escolar',
            'raw_payload' => [
                'eventType' => 'safetyEvent',
                'happenedAtTime' => $eventTime,
                'vehicle' => ['id' => $signal->vehicle_id, 'name' => $signal->vehicle_name],
            ],
        ]);
        $alert->update(['event_description' => 'Frenado brusco en zona escolar']);

        Http::fake([
            'api.samsara.com/*' => Http::response([
                'data' => [
                    [
                        'id' => 'safety-evt-2',
                        'asset' => ['id' => $signal->vehicle_id, 'name' => $signal->vehicle_name],
                        'behaviorLabels' => [['name' => 'Hard Braking']],
                        'startMs' => (int) (now()->getTimestampMs()),
                        'createdAtTime' => $eventTime,
                    ],
                ],
            ], 200),
            '*/alerts/ingest' => Http::response($this->getSuccessAiResponse(), 200),
        ]);

        $this->runJob(new ProcessAlertJob($alert));

        $signal->refresh();
        $this->assertEquals('Frenado brusco en zona escolar', $signal->event_description);
    }

    public function test_passes_unknown_behavior_name_through_untranslated(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);

        $eventTime = now()->toIso8601String();
        ['alert' => $alert, 'signal' => $signal] = $this->createPendingAlert($this->company);
        $signal->update([
            'event_description' => 'Safety Event',
            'raw_payload' => [
                'eventType' => 'safetyEvent',
                'happenedAtTime' => $eventTime,
                'vehicle' => ['id' => $signal->vehicle_id, 'name' => $signal->vehicle_name],
            ],
        ]);
        $alert->update(['event_description' => 'Safety Event']);

        Http::fake([
            'api.samsara.com/*' => Http::response([
                'data' => [
                    [
                        'id' => 'safety-evt-3',
                        'asset' => ['id' => $signal->vehicle_id, 'name' => $signal->vehicle_name],
                        'behaviorLabels' => [['name' => 'UnknownBehavior42']],
                        'startMs' => (int) (now()->getTimestampMs()),
                        'createdAtTime' => $eventTime,
                    ],
                ],
            ], 200),
            '*/alerts/ingest' => Http::response($this->getSuccessAiResponse(), 200),
        ]);

        $this->runJob(new ProcessAlertJob($alert));

        $signal->refresh();
        $this->assertEquals('UnknownBehavior42', $signal->event_description);
    }

    // ─── AI response with investigation_plan & recommended_actions ───

    public function test_persists_investigation_plan_and_recommended_actions_from_ai(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceSuccess([
            'assessment' => [
                'verdict' => 'confirmed_violation',
                'likelihood' => 'high',
                'confidence' => 0.92,
                'reasoning' => 'Violation confirmed.',
                'requires_monitoring' => false,
                'risk_escalation' => 'warn',
                'recommended_actions' => [
                    'Contact driver supervisor',
                    'Schedule vehicle inspection',
                    'File incident report',
                ],
            ],
            'alert_context' => [
                'alert_kind' => 'safety',
                'triage_notes' => 'Safety event.',
                'investigation_plan' => [
                    'Review dashcam footage',
                    'Check vehicle maintenance logs',
                    'Interview driver',
                ],
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $actions = $alert->getRecommendedActionsArray();
        $this->assertCount(3, $actions);
        $this->assertContains('Contact driver supervisor', $actions);

        $steps = $alert->getInvestigationStepsArray();
        $this->assertCount(3, $steps);
        $this->assertContains('Review dashcam footage', $steps);
    }

    public function test_monitoring_also_persists_investigation_plan(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceSuccess([
            'assessment' => [
                'verdict' => 'uncertain',
                'likelihood' => 'medium',
                'confidence' => 0.5,
                'reasoning' => 'Needs more data.',
                'requires_monitoring' => true,
                'monitoring_reason' => 'Waiting for camera.',
                'next_check_minutes' => 20,
                'risk_escalation' => 'monitor',
                'recommended_actions' => ['Wait for camera footage'],
            ],
            'alert_context' => [
                'alert_kind' => 'safety',
                'investigation_plan' => ['Request dashcam', 'Re-evaluate in 20 min'],
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $steps = $alert->getInvestigationStepsArray();
        $this->assertCount(2, $steps);
        $this->assertContains('Request dashcam', $steps);
    }

    // ─── Preload with DB safety events ───────────────────────────────

    public function test_preload_uses_safety_events_from_database(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);

        ['alert' => $alert, 'signal' => $signal] = $this->createPendingAlert($this->company);
        $signal->update([
            'raw_payload' => [
                'happenedAtTime' => now()->toIso8601String(),
                'vehicle' => ['id' => $signal->vehicle_id, 'name' => $signal->vehicle_name],
                'eventType' => 'safetyEvent',
            ],
        ]);

        \App\Models\SafetySignal::factory()->create([
            'company_id' => $this->company->id,
            'vehicle_id' => $signal->vehicle_id,
            'vehicle_name' => $signal->vehicle_name,
            'occurred_at' => now()->subMinute(),
            'primary_behavior_label' => 'Hard Braking',
        ]);

        Http::fake([
            'api.samsara.com/*' => Http::response(['data' => []], 200),
            '*/alerts/ingest' => Http::response($this->getSuccessAiResponse(), 200),
        ]);

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
    }

    // ─── Preload skipped when no event context ───────────────────────

    public function test_preload_skipped_when_payload_has_no_event_context(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);

        ['alert' => $alert, 'signal' => $signal] = $this->createPendingAlert($this->company);
        $signal->update(['raw_payload' => ['some_other_data' => true]]);

        Http::fake([
            'api.samsara.com/*' => Http::response(['data' => []], 200),
            '*/alerts/ingest' => Http::response($this->getSuccessAiResponse(), 200),
        ]);

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
    }

    // ─── Incident creation for monitoring path ────────────────────────

    public function test_creates_incident_for_monitoring_path_with_emergency_escalation(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class, \App\Jobs\RecordUsageEventJob::class]);
        $this->mockAiServiceSuccess([
            'assessment' => [
                'verdict' => 'real_panic',
                'likelihood' => 'high',
                'confidence' => 0.60,
                'reasoning' => 'Panic detected but needs confirmation.',
                'requires_monitoring' => true,
                'monitoring_reason' => 'Confirming panic event.',
                'next_check_minutes' => 5,
                'risk_escalation' => 'emergency',
                'recommended_actions' => ['Call driver'],
            ],
            'notification_decision' => [
                'should_notify' => true,
                'escalation_level' => 'high',
                'channels_to_use' => ['call'],
                'recipients' => [['type' => 'monitoring_team', 'priority' => 1]],
                'message_text' => 'Panic alert.',
                'reason' => 'Emergency.',
            ],
        ]);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createFullAlert(
            $this->company,
            ['event_type' => 'AlertIncident', 'event_description' => 'Panic button pressed'],
            ['ai_status' => Alert::STATUS_PENDING, 'severity' => Alert::SEVERITY_CRITICAL]
        );

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_INVESTIGATING, $alert->ai_status);
        Bus::assertDispatched(RevalidateAlertJob::class);
    }

    // ─── AI config time windows used in preload ─────────────────────

    public function test_uses_company_ai_config_time_windows(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);

        $settings = $this->company->settings ?? [];
        $settings['ai_config'] = array_merge($settings['ai_config'] ?? [], [
            'investigation_windows' => [
                'vehicle_stats_before_minutes' => 10,
                'vehicle_stats_after_minutes' => 5,
                'safety_events_before_minutes' => 60,
                'safety_events_after_minutes' => 20,
            ],
        ]);
        $this->company->update(['settings' => $settings]);

        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
    }

    // ─── Contact resolver payload enrichment ────────────────────────

    public function test_enriches_payload_with_contact_data(): void
    {
        Bus::fake([SendNotificationJob::class, \App\Jobs\RecordUsageEventJob::class]);

        \App\Models\Contact::factory()->monitoringTeam()->create([
            'company_id' => $this->company->id,
            'phone' => '+525551112222',
        ]);

        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->runJob(new ProcessAlertJob($alert));

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/alerts/ingest')) {
                return false;
            }
            $payload = $request->data()['payload'] ?? [];
            return isset($payload['company_id']) && isset($payload['company_config']);
        });
    }

    // ─── Helper to get default successful response ──────────────────

    private function getSuccessAiResponse(): array
    {
        return [
            'success' => true,
            'assessment' => [
                'verdict' => 'confirmed_violation',
                'likelihood' => 'high',
                'confidence' => 0.92,
                'reasoning' => 'Clear safety violation.',
                'recommended_actions' => ['Notify supervisor'],
                'risk_escalation' => 'warn',
                'requires_monitoring' => false,
            ],
            'alert_context' => [
                'alert_kind' => 'safety',
                'triage_notes' => 'Safety event.',
                'investigation_strategy' => 'Review data.',
                'investigation_plan' => ['Check stats'],
            ],
            'human_message' => 'Violación de seguridad confirmada.',
            'notification_decision' => [
                'should_notify' => true,
                'escalation_level' => 'low',
                'message_text' => 'Alert.',
                'channels_to_use' => ['sms'],
                'recipients' => [['type' => 'monitoring_team', 'priority' => 1]],
                'reason' => 'Safety event.',
            ],
            'execution' => [
                'total_tokens' => 1500,
                'cost_estimate' => 0.0045,
                'agents_executed' => ['triage', 'investigator', 'final', 'notification'],
            ],
        ];
    }
}
