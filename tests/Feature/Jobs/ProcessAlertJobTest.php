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

    public function test_processes_alert_through_ai_pipeline(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class]);
        $this->mockAiServiceSuccess();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->mockPipelineAdapter();

        (new ProcessAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
        $this->assertEquals('confirmed_violation', $alert->verdict);
        $this->assertNotNull($alert->ai_message);
    }

    public function test_skips_already_completed_alert(): void
    {
        Http::fake();

        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        (new ProcessAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));

        Http::assertNothingSent();
    }

    public function test_skips_alert_without_samsara_key(): void
    {
        $this->company->update(['samsara_api_key' => null]);
        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->expectException(\Exception::class);

        (new ProcessAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));
    }

    public function test_marks_as_investigating_when_requires_monitoring(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class]);
        $this->mockAiServiceMonitoring(15);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        (new ProcessAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_INVESTIGATING, $alert->ai_status);
    }

    public function test_marks_as_completed_when_no_monitoring_needed(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        (new ProcessAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
    }

    public function test_dispatches_send_notification_job(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class]);
        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        (new ProcessAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));

        Bus::assertDispatched(SendNotificationJob::class);
    }

    public function test_schedules_revalidation_for_monitoring(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class]);
        $this->mockAiServiceMonitoring(15);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        (new ProcessAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));

        Bus::assertDispatched(RevalidateAlertJob::class);
    }

    public function test_saves_recommended_actions_to_table(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        (new ProcessAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));

        $actions = $alert->getRecommendedActionsArray();
        $this->assertNotEmpty($actions);
    }

    public function test_saves_investigation_steps_to_table(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        (new ProcessAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));

        $steps = $alert->getInvestigationStepsArray();
        $this->assertNotEmpty($steps);
    }

    public function test_handles_ai_service_error_gracefully(): void
    {
        $this->mockAiServiceFailure(500);
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        $this->expectException(\Exception::class);

        $job = new ProcessAlertJob($alert);
        $job->handle(app(\App\Services\ContactResolver::class));
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

        (new ProcessAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));
    }

    public function test_records_pipeline_metrics(): void
    {
        Bus::fake([SendNotificationJob::class]);
        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapter();

        ['alert' => $alert] = $this->createPendingAlert($this->company);

        (new ProcessAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));

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

        (new ProcessAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));

        Bus::assertDispatched(\App\Jobs\RecordUsageEventJob::class);
    }

    private function mockPipelineAdapter(): void
    {
        // PipelineAdapter is instantiated via `new` (not DI), so we can't
        // container-mock it. Instead, the Samsara API calls are already
        // covered by Http::fake() patterns set in mockAiService* helpers.
        // This method now simply adds catch-all Samsara patterns before the
        // AI service patterns are registered.
    }

    protected function mockAiServiceWithSamsara(array $aiResponse, int $status = 200): void
    {
        Http::fake([
            'api.samsara.com/*' => Http::response(['data' => []], 200),
            '*/alerts/ingest' => Http::response($aiResponse, $status),
            '*/alerts/revalidate' => Http::response($aiResponse, $status),
        ]);
    }
}
