<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\ProcessAlertJob;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Signal;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class SamsaraWebhookTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    private function ensureVehicle(string $samsaraId = '12345', string $name = 'T-001', ?Company $company = null): Vehicle
    {
        $company ??= $this->company;

        return Vehicle::firstOrCreate(
            ['samsara_id' => $samsaraId],
            [
                'company_id' => $company->id,
                'name' => $name,
                'vin' => fake()->bothify('?????????????????'),
                'license_plate' => fake()->bothify('???-##-##'),
                'make' => 'Kenworth',
                'model' => 'T680',
                'year' => 2023,
            ],
        );
    }

    private function webhookPayload(array $overrides = []): array
    {
        $vehicleId = $overrides['vehicle_id'] ?? '12345';
        $vehicleName = $overrides['vehicle_name'] ?? 'T-001';
        $eventId = $overrides['event_id'] ?? (string) Str::uuid();

        $this->ensureVehicle($vehicleId, $vehicleName);

        return [
            'eventId' => $eventId,
            'eventType' => $overrides['eventType'] ?? 'AlertIncident',
            'orgId' => 'org_123',
            'webhookId' => 'wh_123',
            'vehicleId' => $vehicleId,
            'vehicleName' => $vehicleName,
            'data' => [
                'alert' => [
                    'id' => 'alert_123',
                    'name' => 'Test Alert',
                    'severity' => 'critical',
                ],
                'happenedAtTime' => now()->toIso8601String(),
                'resolvedAt' => null,
            ],
        ];
    }

    public function test_processes_valid_webhook_payload(): void
    {
        Bus::fake([ProcessAlertJob::class]);

        $this->postJson('/api/webhooks/samsara', $this->webhookPayload())
            ->assertStatus(202);
    }

    public function test_creates_signal_and_alert(): void
    {
        Bus::fake([ProcessAlertJob::class]);

        $payload = $this->webhookPayload();

        $this->postJson('/api/webhooks/samsara', $payload)
            ->assertStatus(202);

        $this->assertDatabaseHas('signals', [
            'company_id' => $this->company->id,
        ]);

        $this->assertDatabaseHas('alerts', [
            'company_id' => $this->company->id,
        ]);
    }

    public function test_dispatches_process_alert_job(): void
    {
        Bus::fake([ProcessAlertJob::class]);

        $this->postJson('/api/webhooks/samsara', $this->webhookPayload())
            ->assertStatus(202);

        Bus::assertDispatched(ProcessAlertJob::class);
    }

    public function test_deduplicates_by_samsara_event_id(): void
    {
        Bus::fake([ProcessAlertJob::class]);

        $eventId = (string) Str::uuid();
        $payload = $this->webhookPayload(['event_id' => $eventId]);

        $this->postJson('/api/webhooks/samsara', $payload)->assertStatus(202);
        $this->postJson('/api/webhooks/samsara', $payload)->assertOk();

        Bus::assertDispatchedTimes(ProcessAlertJob::class, 1);
    }

    public function test_gracefully_handles_empty_payload(): void
    {
        $this->postJson('/api/webhooks/samsara', [])
            ->assertStatus(202);
    }

    public function test_handles_unknown_vehicle(): void
    {
        Bus::fake([ProcessAlertJob::class]);

        $payload = [
            'eventId' => (string) Str::uuid(),
            'eventType' => 'AlertIncident',
            'vehicleId' => 'unknown_vehicle_999',
            'vehicleName' => 'Unknown',
            'data' => [
                'happenedAtTime' => now()->toIso8601String(),
            ],
        ];

        $this->postJson('/api/webhooks/samsara', $payload)
            ->assertStatus(202);
    }

    public function test_multi_tenant_routing(): void
    {
        Bus::fake([ProcessAlertJob::class]);

        $vehicleId = '77777';
        $this->ensureVehicle($vehicleId, 'Tenant-Vehicle');

        $payload = $this->webhookPayload(['vehicle_id' => $vehicleId, 'vehicle_name' => 'Tenant-Vehicle']);

        $this->postJson('/api/webhooks/samsara', $payload)
            ->assertStatus(202);

        $alert = Alert::where('company_id', $this->company->id)->first();
        $this->assertNotNull($alert);
        $this->assertEquals($this->company->id, $alert->company_id);
    }
}
