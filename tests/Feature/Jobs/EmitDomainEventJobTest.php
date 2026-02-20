<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EmitDomainEventJob;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class EmitDomainEventJobTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_creates_domain_event(): void
    {
        $job = new EmitDomainEventJob(
            companyId: $this->company->id,
            entityType: 'alert',
            entityId: '123',
            eventType: 'alert.created',
            payload: ['status' => 'pending'],
            actorType: 'system',
            actorId: null,
            traceparent: null,
            correlationId: '123',
        );

        $job->handle();

        $this->assertDatabaseHas('domain_events', [
            'company_id' => $this->company->id,
            'entity_type' => 'alert',
            'entity_id' => '123',
            'event_type' => 'alert.created',
        ]);
    }
}
