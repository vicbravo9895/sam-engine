<?php

namespace Tests\Feature\DomainEvents;

use App\Jobs\EmitDomainEventJob;
use App\Jobs\ProcessAlertJob;
use App\Models\Company;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class WebhookEmitsDomainEventTest extends TestCase
{
    use RefreshDatabase;

    private function samsaraPayload(string $vehicleId, string $vehicleName = 'T-001'): array
    {
        return [
            'eventType' => 'AlertIncident',
            'eventId' => 'evt_' . uniqid(),
            'data' => [
                'happenedAtTime' => now()->toIso8601String(),
                'conditions' => [
                    [
                        'description' => 'Panic Button',
                        'details' => [
                            [
                                'vehicle' => [
                                    'id' => $vehicleId,
                                    'name' => $vehicleName,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_webhook_emits_signal_ingested_when_ledger_active(): void
    {
        Bus::fake([EmitDomainEventJob::class, ProcessAlertJob::class]);

        $company = Company::factory()->withSamsaraApiKey()->create();
        Feature::for($company)->activate('ledger-v1');

        $vehicle = Vehicle::create([
            'company_id' => $company->id,
            'samsara_id' => 'veh_123',
            'name' => 'T-001',
        ]);

        $response = $this->postJson('/api/webhooks/samsara', $this->samsaraPayload('veh_123'));
        $response->assertStatus(202);

        Bus::assertDispatched(EmitDomainEventJob::class, function (EmitDomainEventJob $job) use ($company) {
            return $job->companyId === $company->id
                && $job->entityType === 'signal'
                && $job->eventType === 'signal.ingested'
                && $job->payload['event_type'] === 'AlertIncident'
                && $job->payload['vehicle_id'] === 'veh_123';
        });
    }

    public function test_webhook_does_not_emit_domain_event_when_ledger_inactive(): void
    {
        Bus::fake([EmitDomainEventJob::class, ProcessAlertJob::class]);

        $company = Company::factory()->withSamsaraApiKey()->create();
        Feature::for($company)->deactivate('ledger-v1');

        $vehicle = Vehicle::create([
            'company_id' => $company->id,
            'samsara_id' => 'veh_456',
            'name' => 'T-002',
        ]);

        $response = $this->postJson('/api/webhooks/samsara', $this->samsaraPayload('veh_456'));
        $response->assertStatus(202);

        Bus::assertNotDispatched(EmitDomainEventJob::class);
    }
}
