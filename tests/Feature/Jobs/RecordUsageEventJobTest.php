<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RecordUsageEventJob;
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

    public function test_records_usage_event_when_company_exists(): void
    {
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

    public function test_skips_when_company_not_found(): void
    {
        $job = new RecordUsageEventJob(
            companyId: 99999,
            meter: 'alerts_processed',
            qty: 1,
            idempotencyKey: 'test-key-' . uniqid(),
        );

        $job->handle();

        $this->assertDatabaseMissing('usage_events', [
            'company_id' => 99999,
            'meter' => 'alerts_processed',
        ]);
    }
}
