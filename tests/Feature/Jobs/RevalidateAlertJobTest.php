<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RevalidateAlertJob;
use App\Jobs\SendNotificationJob;
use App\Models\Alert;
use App\Models\AlertAi;
use App\Samsara\Client\PipelineAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;
use Tests\Traits\MocksExternalServices;

class RevalidateAlertJobTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline, MocksExternalServices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_revalidates_investigating_alert(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class]);
        $this->mockAiServiceSuccess();

        ['alert' => $alert, 'ai' => $ai] = $this->createInvestigatingAlert($this->company);

        $this->mockPipelineAdapterForRevalidation();

        (new RevalidateAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
    }

    public function test_skips_non_investigating_alert(): void
    {
        Http::fake();

        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        (new RevalidateAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));

        Http::assertNothingSent();
    }

    public function test_completes_after_max_investigations(): void
    {
        Bus::fake([SendNotificationJob::class]);
        Http::fake();

        ['alert' => $alert, 'ai' => $ai] = $this->createInvestigatingAlert($this->company);
        $ai->update(['investigation_count' => 10]);

        (new RevalidateAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));

        $alert->refresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
        $this->assertEquals('needs_review', $alert->verdict);
    }

    public function test_schedules_next_revalidation(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class]);
        $this->mockAiServiceMonitoring(15);
        $this->mockPipelineAdapterForRevalidation();

        ['alert' => $alert] = $this->createInvestigatingAlert($this->company);

        (new RevalidateAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));

        Bus::assertDispatched(RevalidateAlertJob::class);
    }

    public function test_persists_investigation_history(): void
    {
        Bus::fake([SendNotificationJob::class, RevalidateAlertJob::class]);
        $this->mockAiServiceSuccess();
        $this->mockPipelineAdapterForRevalidation();

        ['alert' => $alert, 'ai' => $ai] = $this->createInvestigatingAlert($this->company);

        (new RevalidateAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));

        $ai->refresh();
        $this->assertNotNull($ai->ai_assessment);
    }

    public function test_handles_ai_service_error(): void
    {
        $this->mockAiServiceFailure(500);
        $this->mockPipelineAdapterForRevalidation();

        ['alert' => $alert] = $this->createInvestigatingAlert($this->company);

        $this->expectException(\Exception::class);

        (new RevalidateAlertJob($alert))->handle(app(\App\Services\ContactResolver::class));
    }

    private function mockPipelineAdapterForRevalidation(): void
    {
        $this->app->bind(PipelineAdapter::class, function () {
            $mock = \Mockery::mock(PipelineAdapter::class);
            $mock->shouldReceive('reloadDataForRevalidation')->andReturn([
                'vehicle_stats' => [],
                'new_safety_events' => [],
            ]);
            $mock->shouldReceive('preloadAllData')->andReturn([]);
            return $mock;
        });
    }
}
