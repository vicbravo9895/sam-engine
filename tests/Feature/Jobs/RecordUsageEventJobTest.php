<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RecordUsageEventJob;
use Laravel\Pennant\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class RecordUsageEventJobTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_records_usage_event_when_feature_active(): void
    {
        Feature::define('metering-v1', fn () => true);

        $job = new RecordUsageEventJob(
            companyId: $this->company->id,
            meter: 'alerts_processed',
            qty: 1,
            idempotencyKey: 'test-key-' . uniqid(),
        );

        $job->handle();

        $this->assertDatabaseHas('usage_events', [
            'company_id' => $this->company->id,
            'meter' => 'alerts_processed',
        ]);
    }

    public function test_skips_when_feature_inactive(): void
    {
        Feature::define('metering-v1', fn () => false);

        $job = new RecordUsageEventJob(
            companyId: $this->company->id,
            meter: 'alerts_processed',
            qty: 1,
            idempotencyKey: 'test-key-' . uniqid(),
        );

        $job->handle();

        $this->assertDatabaseMissing('usage_events', [
            'company_id' => $this->company->id,
            'meter' => 'alerts_processed',
        ]);
    }
}
