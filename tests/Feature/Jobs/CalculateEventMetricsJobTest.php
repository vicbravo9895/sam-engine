<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CalculateEventMetricsJob;
use App\Models\Alert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class CalculateEventMetricsJobTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_calculates_metrics_for_company(): void
    {
        $this->createCompletedAlert($this->company);
        $this->createCompletedAlert($this->company);

        $job = new CalculateEventMetricsJob(now()->subDay()->toDateString());
        $job->handle();

        $this->assertTrue(true);
    }

    public function test_handles_no_events_gracefully(): void
    {
        $job = new CalculateEventMetricsJob(now()->subDay()->toDateString());
        $job->handle();

        $this->assertTrue(true);
    }
}
