<?php

namespace Tests\Feature\DomainEvents;

use App\Jobs\EmitDomainEventJob;
use App\Models\Company;
use App\Models\DomainEvent;
use App\Services\DomainEventEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class DomainEventEmitterTest extends TestCase
{
    use RefreshDatabase;

    public function test_emitter_dispatches_job_when_ledger_flag_active(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $company = Company::factory()->create();
        Feature::for($company)->activate('ledger-v1');

        DomainEventEmitter::emit(
            companyId: $company->id,
            entityType: 'alert',
            entityId: '42',
            eventType: 'alert.completed',
            payload: ['verdict' => 'confirmed'],
        );

        Bus::assertDispatched(EmitDomainEventJob::class, function (EmitDomainEventJob $job) use ($company) {
            return $job->companyId === $company->id
                && $job->entityType === 'alert'
                && $job->entityId === '42'
                && $job->eventType === 'alert.completed'
                && $job->payload['verdict'] === 'confirmed';
        });
    }

    public function test_emitter_skips_when_ledger_flag_inactive(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $company = Company::factory()->create();
        Feature::for($company)->deactivate('ledger-v1');

        DomainEventEmitter::emit(
            companyId: $company->id,
            entityType: 'alert',
            entityId: '1',
            eventType: 'alert.completed',
        );

        Bus::assertNotDispatched(EmitDomainEventJob::class);
    }

    public function test_emitter_resolves_traceparent_from_container(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $company = Company::factory()->create();
        Feature::for($company)->activate('ledger-v1');

        $expectedTraceparent = '00-aaaabbbbccccddddaaaabbbbccccdddd-1122334455667788-01';
        app()->instance('traceparent', $expectedTraceparent);

        DomainEventEmitter::emit(
            companyId: $company->id,
            entityType: 'signal',
            entityId: '99',
            eventType: 'signal.ingested',
        );

        Bus::assertDispatched(EmitDomainEventJob::class, function (EmitDomainEventJob $job) use ($expectedTraceparent) {
            return $job->traceparent === $expectedTraceparent;
        });
    }

    public function test_job_persists_domain_event_in_database(): void
    {
        $company = Company::factory()->create();

        $job = new EmitDomainEventJob(
            companyId: $company->id,
            entityType: 'alert',
            entityId: '10',
            eventType: 'alert.completed',
            payload: ['verdict' => 'false_alarm'],
            actorType: 'system',
            traceparent: '00-abcd1234abcd1234abcd1234abcd1234-1234567890abcdef-01',
            correlationId: 'corr-123',
        );

        $job->handle();

        $this->assertDatabaseHas('domain_events', [
            'company_id' => $company->id,
            'entity_type' => 'alert',
            'entity_id' => '10',
            'event_type' => 'alert.completed',
            'actor_type' => 'system',
            'traceparent' => '00-abcd1234abcd1234abcd1234abcd1234-1234567890abcdef-01',
            'correlation_id' => 'corr-123',
        ]);

        $event = DomainEvent::first();
        $this->assertEquals(['verdict' => 'false_alarm'], $event->payload);
        $this->assertNotNull($event->id);
        $this->assertNotNull($event->occurred_at);
    }

    public function test_emitter_passes_explicit_traceparent_over_container(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $company = Company::factory()->create();
        Feature::for($company)->activate('ledger-v1');

        app()->instance('traceparent', '00-containercontainercontainerco-1111111111111111-01');

        $explicit = '00-explicitexplicitexplicitexpl-2222222222222222-01';
        DomainEventEmitter::emit(
            companyId: $company->id,
            entityType: 'alert',
            entityId: '1',
            eventType: 'alert.completed',
            traceparent: $explicit,
        );

        Bus::assertDispatched(EmitDomainEventJob::class, function (EmitDomainEventJob $job) use ($explicit) {
            return $job->traceparent === $explicit;
        });
    }
}
