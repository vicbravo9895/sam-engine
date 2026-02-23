<?php

namespace Tests\Feature\Controllers;

use App\Jobs\ProcessAlertJob;
use App\Models\Alert;
use App\Models\AlertSource;
use App\Models\Company;
use App\Models\PendingWebhook;
use App\Models\Signal;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class SamsaraWebhookControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    private function validPayload(array $overrides = []): array
    {
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        return array_merge([
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'AlertIncident',
            'severity' => 'critical',
            'data' => [
                'happenedAtTime' => now()->toIso8601String(),
                'conditions' => [
                    [
                        'description' => 'Panic Button',
                        'details' => [
                            ['vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name]],
                        ],
                    ],
                ],
            ],
        ], $overrides);
    }

    private function payloadWithVehicleTopLevel(Vehicle $vehicle, array $overrides = []): array
    {
        return array_merge([
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'AlertIncident',
            'severity' => 'warning',
            'data' => ['happenedAtTime' => now()->toIso8601String()],
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
        ], $overrides);
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function test_webhook_creates_signal_alert_and_dispatches_job(): void
    {
        Bus::fake([ProcessAlertJob::class]);

        $response = $this->postJson('/api/webhooks/samsara', $this->validPayload());

        $response->assertStatus(202)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseCount('signals', 1);
        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseCount('alert_sources', 1);

        Bus::assertDispatched(ProcessAlertJob::class);
    }

    public function test_webhook_creates_alert_with_pending_status(): void
    {
        Bus::fake([ProcessAlertJob::class]);

        $this->postJson('/api/webhooks/samsara', $this->validPayload());

        $alert = Alert::first();
        $this->assertEquals(Alert::STATUS_PENDING, $alert->ai_status);
        $this->assertEquals($this->company->id, $alert->company_id);
    }

    public function test_webhook_creates_alert_source_with_primary_role(): void
    {
        Bus::fake([ProcessAlertJob::class]);

        $this->postJson('/api/webhooks/samsara', $this->validPayload());

        $source = AlertSource::first();
        $this->assertEquals('primary', $source->role);
    }

    // =========================================================================
    // Vehicle info extraction variants
    // =========================================================================

    public function test_extracts_vehicle_from_top_level_vehicle_key(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = $this->payloadWithVehicleTopLevel($vehicle);

        $response = $this->postJson('/api/webhooks/samsara', $payload);

        $response->assertStatus(202);
        $signal = Signal::first();
        $this->assertEquals($vehicle->samsara_id, $signal->vehicle_id);
        $this->assertEquals($vehicle->name, $signal->vehicle_name);
    }

    public function test_extracts_vehicle_from_vehicleId_field(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'AlertIncident',
            'severity' => 'info',
            'vehicleId' => $vehicle->samsara_id,
            'vehicleName' => $vehicle->name,
            'data' => ['happenedAtTime' => now()->toIso8601String()],
        ];

        $response = $this->postJson('/api/webhooks/samsara', $payload);

        $response->assertStatus(202);
        $signal = Signal::first();
        $this->assertEquals($vehicle->samsara_id, $signal->vehicle_id);
    }

    public function test_extracts_vehicle_from_conditions_details(): void
    {
        Bus::fake([ProcessAlertJob::class]);

        $response = $this->postJson('/api/webhooks/samsara', $this->validPayload());

        $response->assertStatus(202);
        $this->assertDatabaseCount('signals', 1);
    }

    // =========================================================================
    // Event type detection
    // =========================================================================

    public function test_determines_event_type_from_alertType(): void
    {
        Bus::fake([ProcessAlertJob::class]);

        $this->postJson('/api/webhooks/samsara', $this->validPayload(['alertType' => 'PanicButtonAlert']));

        $signal = Signal::first();
        $this->assertEquals('PanicButtonAlert', $signal->event_type);
    }

    public function test_determines_event_type_from_eventType(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'eventType' => 'SafetyEvent',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => ['happenedAtTime' => now()->toIso8601String()],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $signal = Signal::first();
        $this->assertEquals('SafetyEvent', $signal->event_type);
    }

    public function test_determines_event_type_from_type_field(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'type' => 'DeviceConnectionStatus',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => ['happenedAtTime' => now()->toIso8601String()],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $signal = Signal::first();
        $this->assertEquals('DeviceConnectionStatus', $signal->event_type);
    }

    public function test_returns_unknown_when_no_event_type_found(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => ['happenedAtTime' => now()->toIso8601String()],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $signal = Signal::first();
        $this->assertEquals('unknown', $signal->event_type);
    }

    // =========================================================================
    // Severity determination
    // =========================================================================

    public function test_severity_from_payload_severity_field(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $cases = [
            'critical' => Alert::SEVERITY_CRITICAL,
            'high' => Alert::SEVERITY_CRITICAL,
            'panic' => Alert::SEVERITY_CRITICAL,
            'warning' => Alert::SEVERITY_WARNING,
            'medium' => Alert::SEVERITY_WARNING,
            'low' => Alert::SEVERITY_INFO,
            'info' => Alert::SEVERITY_INFO,
        ];

        foreach ($cases as $input => $expected) {
            Signal::query()->delete();
            Alert::query()->delete();

            $payload = [
                'eventId' => 'evt-' . fake()->uuid(),
                'alertType' => 'TestAlert',
                'severity' => $input,
                'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
                'data' => ['happenedAtTime' => now()->toIso8601String()],
            ];

            $this->postJson('/api/webhooks/samsara', $payload);

            $alert = Alert::latest('id')->first();
            $this->assertEquals($expected, $alert->severity, "Failed for severity input: {$input}");
        }
    }

    public function test_severity_from_level_field(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'TestAlert',
            'level' => 'HIGH',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => ['happenedAtTime' => now()->toIso8601String()],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $alert = Alert::first();
        $this->assertEquals(Alert::SEVERITY_CRITICAL, $alert->severity);
    }

    public function test_severity_inferred_from_panic_description(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'AlertIncident',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => [
                'happenedAtTime' => now()->toIso8601String(),
                'conditions' => [
                    ['description' => 'Panic Button'],
                ],
            ],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $alert = Alert::first();
        $this->assertEquals(Alert::SEVERITY_CRITICAL, $alert->severity);
    }

    public function test_severity_inferred_from_collision_description(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'AlertIncident',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => [
                'happenedAtTime' => now()->toIso8601String(),
                'conditions' => [
                    ['description' => 'Collision detected'],
                ],
            ],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $alert = Alert::first();
        $this->assertEquals(Alert::SEVERITY_CRITICAL, $alert->severity);
    }

    public function test_severity_inferred_from_critical_event_type(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'collision',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => ['happenedAtTime' => now()->toIso8601String()],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $alert = Alert::first();
        $this->assertEquals(Alert::SEVERITY_CRITICAL, $alert->severity);
    }

    public function test_severity_inferred_from_warning_description(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'SafetyEvent',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => [
                'happenedAtTime' => now()->toIso8601String(),
                'conditions' => [
                    ['description' => 'Hard Braking'],
                ],
            ],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $alert = Alert::first();
        $this->assertEquals(Alert::SEVERITY_WARNING, $alert->severity);
    }

    public function test_severity_defaults_to_info_when_no_match(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'SomeOtherAlert',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => [
                'happenedAtTime' => now()->toIso8601String(),
                'conditions' => [
                    ['description' => 'Vehicle idle for too long'],
                ],
            ],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $alert = Alert::first();
        $this->assertEquals(Alert::SEVERITY_INFO, $alert->severity);
    }

    // =========================================================================
    // Event description translation
    // =========================================================================

    public function test_translates_known_event_descriptions(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $translations = [
            'Panic Button' => 'Botón de pánico',
            'Hard Braking' => 'Frenado brusco',
            'Harsh Acceleration' => 'Aceleración brusca',
            'Sharp Turn' => 'Giro brusco',
            'Distracted Driving' => 'Conducción distraída',
            'Speeding' => 'Exceso de velocidad',
            'No Seatbelt' => 'Sin cinturón de seguridad',
            'Obstructed Camera' => 'Cámara obstruida',
            'Collision' => 'Colisión',
        ];

        foreach ($translations as $english => $spanish) {
            Signal::query()->delete();
            Alert::query()->delete();

            $payload = [
                'eventId' => 'evt-' . fake()->uuid(),
                'alertType' => 'AlertIncident',
                'severity' => 'info',
                'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
                'data' => [
                    'happenedAtTime' => now()->toIso8601String(),
                    'conditions' => [
                        ['description' => $english],
                    ],
                ],
            ];

            $this->postJson('/api/webhooks/samsara', $payload);

            $signal = Signal::first();
            $this->assertEquals($spanish, $signal->event_description, "Failed translating: {$english}");
        }
    }

    public function test_unknown_description_passes_through_untranslated(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'AlertIncident',
            'severity' => 'info',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => [
                'happenedAtTime' => now()->toIso8601String(),
                'conditions' => [
                    ['description' => 'Some brand new event type'],
                ],
            ],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $signal = Signal::first();
        $this->assertEquals('Some brand new event type', $signal->event_description);
    }

    public function test_null_description_when_no_conditions(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'AlertIncident',
            'severity' => 'info',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => ['happenedAtTime' => now()->toIso8601String()],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $signal = Signal::first();
        $this->assertNull($signal->event_description);
    }

    // =========================================================================
    // Deduplication
    // =========================================================================

    public function test_deduplicates_by_samsara_event_id(): void
    {
        Bus::fake([ProcessAlertJob::class]);

        $payload = $this->validPayload();

        $this->postJson('/api/webhooks/samsara', $payload)->assertStatus(202);
        $this->postJson('/api/webhooks/samsara', $payload)->assertStatus(200)
            ->assertJsonPath('message', 'Duplicate event - already processed');

        $this->assertDatabaseCount('signals', 1);
        $this->assertDatabaseCount('alerts', 1);
    }

    public function test_deduplicates_by_time_window(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();
        $occurredAt = now()->toIso8601String();

        $payload1 = [
            'eventId' => 'evt-first-' . fake()->uuid(),
            'alertType' => 'SafetyEvent',
            'severity' => 'info',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => ['happenedAtTime' => $occurredAt],
        ];

        $payload2 = [
            'eventId' => 'evt-second-' . fake()->uuid(),
            'alertType' => 'SafetyEvent',
            'severity' => 'info',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => ['happenedAtTime' => $occurredAt],
        ];

        $this->postJson('/api/webhooks/samsara', $payload1)->assertStatus(202);
        $this->postJson('/api/webhooks/samsara', $payload2)->assertStatus(200)
            ->assertJsonPath('message', 'Duplicate event - already processed');

        $this->assertDatabaseCount('signals', 1);
    }

    public function test_same_event_type_outside_time_window_is_not_deduplicated(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $signal = Signal::factory()->create([
            'company_id' => $this->company->id,
            'vehicle_id' => $vehicle->samsara_id,
            'event_type' => 'SafetyEvent',
            'occurred_at' => now()->subMinutes(5),
            'samsara_event_id' => 'old-event-id',
        ]);

        $payload = [
            'eventId' => 'evt-new-' . fake()->uuid(),
            'alertType' => 'SafetyEvent',
            'severity' => 'info',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => ['happenedAtTime' => now()->toIso8601String()],
        ];

        $this->postJson('/api/webhooks/samsara', $payload)->assertStatus(202);

        $this->assertDatabaseCount('signals', 2);
    }

    // =========================================================================
    // Unknown vehicle → PendingWebhook
    // =========================================================================

    public function test_unknown_vehicle_creates_pending_webhook(): void
    {
        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'AlertIncident',
            'severity' => 'critical',
            'vehicle' => ['id' => 'unknown-vehicle-999', 'name' => 'Unknown'],
            'data' => ['happenedAtTime' => now()->toIso8601String()],
        ];

        $response = $this->postJson('/api/webhooks/samsara', $payload);

        $response->assertStatus(202)
            ->assertJsonPath('message', 'Vehicle not yet registered. Webhook queued.');

        $this->assertDatabaseHas('pending_webhooks', [
            'vehicle_samsara_id' => 'unknown-vehicle-999',
            'event_type' => 'AlertIncident',
        ]);

        $this->assertDatabaseCount('signals', 0);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_unknown_vehicle_without_vehicle_id_returns_202_without_pending_webhook(): void
    {
        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'AlertIncident',
            'data' => ['happenedAtTime' => now()->toIso8601String()],
        ];

        $response = $this->postJson('/api/webhooks/samsara', $payload);

        $response->assertStatus(202)
            ->assertJsonPath('message', 'Vehicle not yet registered. Webhook queued.');

        $this->assertDatabaseCount('pending_webhooks', 0);
    }

    // =========================================================================
    // Company validation
    // =========================================================================

    public function test_company_without_samsara_api_key_returns_400(): void
    {
        $companyNoKey = Company::factory()->create(['settings' => []]);
        $vehicle = Vehicle::factory()->create(['company_id' => $companyNoKey->id]);

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'AlertIncident',
            'severity' => 'info',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => ['happenedAtTime' => now()->toIso8601String()],
        ];

        $response = $this->postJson('/api/webhooks/samsara', $payload);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Company does not have Samsara API key configured.');
    }

    // =========================================================================
    // Driver info extraction
    // =========================================================================

    public function test_extracts_driver_from_driver_object(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'AlertIncident',
            'severity' => 'info',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'driver' => ['id' => 'drv-123', 'name' => 'John Doe'],
            'data' => ['happenedAtTime' => now()->toIso8601String()],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $signal = Signal::first();
        $this->assertEquals('drv-123', $signal->driver_id);
        $this->assertEquals('John Doe', $signal->driver_name);
    }

    public function test_extracts_driver_from_driverId_field(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'AlertIncident',
            'severity' => 'info',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'driverId' => 'drv-456',
            'driverName' => 'Jane Smith',
            'data' => ['happenedAtTime' => now()->toIso8601String()],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $signal = Signal::first();
        $this->assertEquals('drv-456', $signal->driver_id);
        $this->assertEquals('Jane Smith', $signal->driver_name);
    }

    // =========================================================================
    // Occurred-at extraction
    // =========================================================================

    public function test_extracts_occurred_at_from_data_happenedAtTime(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();
        $time = '2026-02-20T15:30:00+00:00';

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'AlertIncident',
            'severity' => 'info',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => ['happenedAtTime' => $time],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $signal = Signal::first();
        $this->assertEquals('2026-02-20 15:30:00', $signal->occurred_at->format('Y-m-d H:i:s'));
    }

    public function test_extracts_occurred_at_from_eventTime_fallback(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();
        $time = '2026-02-20T12:00:00+00:00';

        $payload = [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'AlertIncident',
            'severity' => 'info',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'eventTime' => $time,
            'data' => [],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $signal = Signal::first();
        $this->assertEquals('2026-02-20 12:00:00', $signal->occurred_at->format('Y-m-d H:i:s'));
    }

    // =========================================================================
    // samsara_event_id extraction
    // =========================================================================

    public function test_extracts_samsara_event_id_from_id_field_as_fallback(): void
    {
        Bus::fake([ProcessAlertJob::class]);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $payload = [
            'id' => 'fallback-event-id-123',
            'alertType' => 'AlertIncident',
            'severity' => 'info',
            'vehicle' => ['id' => $vehicle->samsara_id, 'name' => $vehicle->name],
            'data' => ['happenedAtTime' => now()->toIso8601String()],
        ];

        $this->postJson('/api/webhooks/samsara', $payload);

        $signal = Signal::first();
        $this->assertEquals('fallback-event-id-123', $signal->samsara_event_id);
    }

    // =========================================================================
    // Trace header
    // =========================================================================

    public function test_accepts_x_trace_id_header(): void
    {
        Bus::fake([ProcessAlertJob::class]);

        $response = $this->postJson(
            '/api/webhooks/samsara',
            $this->validPayload(),
            ['X-Trace-ID' => 'trace-abc-123']
        );

        $response->assertStatus(202);
    }

    // =========================================================================
    // No auth required
    // =========================================================================

    public function test_webhook_does_not_require_authentication(): void
    {
        Bus::fake([ProcessAlertJob::class]);

        $response = $this->postJson('/api/webhooks/samsara', $this->validPayload());

        $response->assertStatus(202);
    }

    // =========================================================================
    // Response shape
    // =========================================================================

    public function test_successful_response_includes_alert_id(): void
    {
        Bus::fake([ProcessAlertJob::class]);

        $response = $this->postJson('/api/webhooks/samsara', $this->validPayload());

        $response->assertStatus(202)
            ->assertJsonStructure(['status', 'message', 'alert_id']);
    }

    // =========================================================================
    // Empty / malformed payloads
    // =========================================================================

    public function test_empty_payload_returns_202_queued_as_unknown_vehicle(): void
    {
        $response = $this->postJson('/api/webhooks/samsara', []);

        $response->assertStatus(202)
            ->assertJsonPath('message', 'Vehicle not yet registered. Webhook queued.');
    }

    public function test_payload_with_null_vehicle_returns_accepted(): void
    {
        $response = $this->postJson('/api/webhooks/samsara', [
            'eventId' => 'evt-' . fake()->uuid(),
            'alertType' => 'TestEvent',
        ]);

        $response->assertStatus(202);
    }
}
