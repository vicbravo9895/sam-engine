<?php

namespace Tests\Feature\Observers;

use App\Jobs\ProcessAlertJob;
use App\Jobs\SendNotificationJob;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Contact;
use App\Models\SafetySignal;
use App\Models\Signal;
use App\Services\ContactResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class SafetySignalObserverTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        Bus::fake([ProcessAlertJob::class, SendNotificationJob::class]);
    }

    /**
     * Build attributes for a SafetySignal tied to $this->company.
     * Callers can override any field.
     */
    private function safetySignalAttributes(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->company->id,
            'samsara_event_id' => (string) Str::uuid(),
            'vehicle_id' => '1234567890',
            'vehicle_name' => 'T-001',
            'driver_id' => '9876543210',
            'driver_name' => 'Juan Pérez',
            'latitude' => 19.4326,
            'longitude' => -99.1332,
            'primary_behavior_label' => 'Crash',
            'behavior_labels' => ['Crash'],
            'severity' => 'critical',
            'occurred_at' => now()->subMinutes(5),
        ], $overrides);
    }

    /**
     * Configure the company's safety_stream_notify rules so that
     * getMatchedRule() returns the given rule when the signal matches.
     */
    private function configureRule(string $action, array $conditions = ['Crash'], array $extra = []): void
    {
        $rule = array_merge([
            'id' => 'test-rule',
            'conditions' => $conditions,
            'action' => $action,
        ], $extra);

        $this->company->setSetting('ai_config.safety_stream_notify', [
            'enabled' => true,
            'rules' => [$rule],
        ]);
    }

    // =========================================================================
    // 1. Skips when no rule matched
    // =========================================================================

    public function test_skips_when_no_rule_matched(): void
    {
        $this->company->setSetting('ai_config.safety_stream_notify', [
            'enabled' => true,
            'rules' => [
                ['id' => 'only-speeding', 'conditions' => ['SevereSpeeding'], 'action' => 'ai_pipeline'],
            ],
        ]);

        SafetySignal::create($this->safetySignalAttributes([
            'primary_behavior_label' => 'Braking',
            'behavior_labels' => ['Braking'],
        ]));

        $this->assertDatabaseCount('signals', 0);
        $this->assertDatabaseCount('alerts', 0);
        Bus::assertNotDispatched(ProcessAlertJob::class);
        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    // =========================================================================
    // 2. Skips when alert already exists for samsara_event_id
    // =========================================================================

    public function test_skips_when_alert_already_exists_for_samsara_event_id(): void
    {
        $this->configureRule('ai_pipeline');

        $eventId = (string) Str::uuid();

        $existingSignal = Signal::factory()->create([
            'company_id' => $this->company->id,
            'samsara_event_id' => $eventId,
        ]);
        Alert::factory()->create([
            'company_id' => $this->company->id,
            'signal_id' => $existingSignal->id,
        ]);

        SafetySignal::create($this->safetySignalAttributes([
            'samsara_event_id' => $eventId,
        ]));

        $this->assertDatabaseCount('alerts', 1);
        Bus::assertNotDispatched(ProcessAlertJob::class);
    }

    // =========================================================================
    // 3. ai_pipeline action creates Signal + Alert and dispatches ProcessAlertJob
    // =========================================================================

    public function test_ai_pipeline_action_creates_signal_alert_and_dispatches_job(): void
    {
        $this->configureRule('ai_pipeline');

        $eventId = (string) Str::uuid();

        SafetySignal::create($this->safetySignalAttributes([
            'samsara_event_id' => $eventId,
        ]));

        $this->assertDatabaseHas('signals', [
            'company_id' => $this->company->id,
            'samsara_event_id' => $eventId,
            'event_type' => 'AlertIncident',
        ]);

        $this->assertDatabaseHas('alerts', [
            'company_id' => $this->company->id,
            'ai_status' => Alert::STATUS_PENDING,
        ]);

        Bus::assertDispatched(ProcessAlertJob::class);
        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    // =========================================================================
    // 4. 'notify' action is treated as 'ai_pipeline'
    // =========================================================================

    public function test_notify_action_treated_as_ai_pipeline(): void
    {
        $this->configureRule('notify');

        SafetySignal::create($this->safetySignalAttributes());

        Bus::assertDispatched(ProcessAlertJob::class);
        Bus::assertNotDispatched(SendNotificationJob::class);

        $this->assertDatabaseHas('alerts', [
            'company_id' => $this->company->id,
            'ai_status' => Alert::STATUS_PENDING,
        ]);
    }

    // =========================================================================
    // 5. notify_immediate dispatches SendNotificationJob
    // =========================================================================

    public function test_notify_immediate_dispatches_send_notification_job(): void
    {
        $this->configureRule('notify_immediate');
        $this->mockContactResolver();

        SafetySignal::create($this->safetySignalAttributes());

        Bus::assertDispatched(SendNotificationJob::class);
        Bus::assertNotDispatched(ProcessAlertJob::class);
    }

    // =========================================================================
    // 6. 'both' action dispatches both jobs
    // =========================================================================

    public function test_both_action_dispatches_both_jobs(): void
    {
        $this->configureRule('both');
        $this->mockContactResolver();

        SafetySignal::create($this->safetySignalAttributes());

        Bus::assertDispatched(ProcessAlertJob::class);
        Bus::assertDispatched(SendNotificationJob::class);
    }

    // =========================================================================
    // 7. Creates Signal with correct data
    // =========================================================================

    public function test_creates_signal_with_correct_data(): void
    {
        $this->configureRule('ai_pipeline');

        $eventId = (string) Str::uuid();
        $occurredAt = now()->subMinutes(10);

        SafetySignal::create($this->safetySignalAttributes([
            'samsara_event_id' => $eventId,
            'vehicle_id' => 'v-100',
            'vehicle_name' => 'Camión Rojo',
            'driver_id' => 'd-200',
            'driver_name' => 'Carlos López',
            'severity' => 'warning',
            'occurred_at' => $occurredAt,
            'primary_behavior_label' => 'Crash',
        ]));

        $signal = Signal::where('samsara_event_id', $eventId)->first();

        $this->assertNotNull($signal);
        $this->assertEquals($this->company->id, $signal->company_id);
        $this->assertEquals('AlertIncident', $signal->event_type);
        $this->assertEquals('v-100', $signal->vehicle_id);
        $this->assertEquals('Camión Rojo', $signal->vehicle_name);
        $this->assertEquals('d-200', $signal->driver_id);
        $this->assertEquals('Carlos López', $signal->driver_name);
        $this->assertEquals('warning', $signal->severity);
        $this->assertNotNull($signal->raw_payload);
        $this->assertEquals($eventId, $signal->raw_payload['eventId']);
    }

    // =========================================================================
    // 8. Creates Alert with pending status
    // =========================================================================

    public function test_creates_alert_with_pending_status(): void
    {
        $this->configureRule('ai_pipeline');

        $eventId = (string) Str::uuid();

        SafetySignal::create($this->safetySignalAttributes([
            'samsara_event_id' => $eventId,
            'severity' => 'critical',
        ]));

        $signal = Signal::where('samsara_event_id', $eventId)->firstOrFail();
        $alert = Alert::where('signal_id', $signal->id)->firstOrFail();

        $this->assertEquals(Alert::STATUS_PENDING, $alert->ai_status);
        $this->assertEquals('critical', $alert->severity);
        $this->assertEquals($this->company->id, $alert->company_id);
        $this->assertEquals($signal->id, $alert->signal_id);
    }

    // =========================================================================
    // 9. Immediate notification uses rule channels when configured
    // =========================================================================

    public function test_immediate_notification_uses_rule_channels_when_configured(): void
    {
        $this->configureRule('notify_immediate', ['Crash'], [
            'channels' => ['sms'],
            'recipients' => ['supervisor'],
        ]);
        $this->mockContactResolver();

        SafetySignal::create($this->safetySignalAttributes());

        Bus::assertDispatched(SendNotificationJob::class, function (SendNotificationJob $job) {
            $decision = $this->getJobDecision($job);
            return in_array('sms', $decision['channels_to_use'])
                && !in_array('whatsapp', $decision['channels_to_use']);
        });
    }

    // =========================================================================
    // 10. Immediate notification falls back to escalation matrix
    // =========================================================================

    public function test_immediate_notification_falls_back_to_escalation_matrix(): void
    {
        $this->configureRule('notify_immediate', ['Crash'], [
            'channels' => [],
            'recipients' => [],
        ]);
        $this->mockContactResolver();

        SafetySignal::create($this->safetySignalAttributes());

        Bus::assertDispatched(SendNotificationJob::class, function (SendNotificationJob $job) {
            $decision = $this->getJobDecision($job);
            return !empty($decision['channels_to_use']);
        });
    }

    // =========================================================================
    // 11. Immediate notification updates alert to completed
    // =========================================================================

    public function test_immediate_notification_updates_alert_to_completed(): void
    {
        $this->configureRule('notify_immediate');
        $this->mockContactResolver();

        $eventId = (string) Str::uuid();

        SafetySignal::create($this->safetySignalAttributes([
            'samsara_event_id' => $eventId,
        ]));

        $signal = Signal::where('samsara_event_id', $eventId)->firstOrFail();
        $alert = Alert::where('signal_id', $signal->id)->firstOrFail();

        $this->assertEquals(Alert::STATUS_COMPLETED, $alert->ai_status);
        $this->assertNotNull($alert->ai_message);
        $this->assertStringContains('T-001', $alert->ai_message);
    }

    // =========================================================================
    // 12. buildRawPayload includes driver when present
    // =========================================================================

    public function test_build_raw_payload_includes_driver_when_present(): void
    {
        $this->configureRule('ai_pipeline');

        $eventId = (string) Str::uuid();

        SafetySignal::create($this->safetySignalAttributes([
            'samsara_event_id' => $eventId,
            'driver_id' => 'd-driver-1',
            'driver_name' => 'María García',
        ]));

        $signal = Signal::where('samsara_event_id', $eventId)->firstOrFail();

        $this->assertArrayHasKey('driver', $signal->raw_payload);
        $this->assertEquals('d-driver-1', $signal->raw_payload['driver']['id']);
        $this->assertEquals('María García', $signal->raw_payload['driver']['name']);
        $this->assertEquals('safety_stream', $signal->raw_payload['_source']);
    }

    public function test_build_raw_payload_excludes_driver_when_absent(): void
    {
        $this->configureRule('ai_pipeline');

        $eventId = (string) Str::uuid();

        SafetySignal::create($this->safetySignalAttributes([
            'samsara_event_id' => $eventId,
            'driver_id' => null,
            'driver_name' => null,
        ]));

        $signal = Signal::where('samsara_event_id', $eventId)->firstOrFail();

        $this->assertNull($signal->raw_payload['driver']);
    }

    // =========================================================================
    // Additional edge cases
    // =========================================================================

    public function test_skips_when_safety_stream_notify_disabled(): void
    {
        $this->company->setSetting('ai_config.safety_stream_notify', [
            'enabled' => false,
            'rules' => [
                ['id' => 'crash-rule', 'conditions' => ['Crash'], 'action' => 'ai_pipeline'],
            ],
        ]);

        SafetySignal::create($this->safetySignalAttributes());

        $this->assertDatabaseCount('signals', 0);
        $this->assertDatabaseCount('alerts', 0);
        Bus::assertNotDispatched(ProcessAlertJob::class);
    }

    public function test_skips_when_company_has_no_samsara_api_key(): void
    {
        $this->company->update(['samsara_api_key' => null]);

        $this->configureRule('ai_pipeline');

        SafetySignal::create($this->safetySignalAttributes());

        $this->assertDatabaseCount('signals', 0);
        $this->assertDatabaseCount('alerts', 0);
        Bus::assertNotDispatched(ProcessAlertJob::class);
    }

    public function test_action_defaults_to_ai_pipeline_when_missing(): void
    {
        $this->company->setSetting('ai_config.safety_stream_notify', [
            'enabled' => true,
            'rules' => [
                ['id' => 'no-action-rule', 'conditions' => ['Crash']],
            ],
        ]);

        SafetySignal::create($this->safetySignalAttributes());

        Bus::assertDispatched(ProcessAlertJob::class);
        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    public function test_reuses_existing_signal_for_same_event_id(): void
    {
        $this->configureRule('ai_pipeline');

        $eventId = (string) Str::uuid();

        $existingSignal = Signal::factory()->create([
            'company_id' => $this->company->id,
            'samsara_event_id' => $eventId,
        ]);

        // No Alert exists yet for this signal, so the observer should proceed
        SafetySignal::create($this->safetySignalAttributes([
            'samsara_event_id' => $eventId,
        ]));

        $this->assertDatabaseCount('signals', 1);

        $alert = Alert::where('signal_id', $existingSignal->id)->firstOrFail();
        $this->assertEquals(Alert::STATUS_PENDING, $alert->ai_status);
    }

    public function test_duplicate_check_is_scoped_to_company(): void
    {
        $this->configureRule('ai_pipeline');

        $eventId = (string) Str::uuid();

        $otherCompany = Company::factory()->withSamsaraApiKey()->create();
        $otherSignal = Signal::factory()->create([
            'company_id' => $otherCompany->id,
            'samsara_event_id' => $eventId,
        ]);
        Alert::factory()->create([
            'company_id' => $otherCompany->id,
            'signal_id' => $otherSignal->id,
        ]);

        SafetySignal::create($this->safetySignalAttributes([
            'samsara_event_id' => $eventId,
        ]));

        $this->assertDatabaseCount('alerts', 2);
        Bus::assertDispatched(ProcessAlertJob::class);
    }

    public function test_immediate_notification_message_contains_vehicle_and_driver(): void
    {
        $this->configureRule('notify_immediate');
        $this->mockContactResolver();

        $eventId = (string) Str::uuid();

        SafetySignal::create($this->safetySignalAttributes([
            'samsara_event_id' => $eventId,
            'vehicle_name' => 'T-999',
            'driver_name' => 'Pedro Ramos',
        ]));

        $signal = Signal::where('samsara_event_id', $eventId)->firstOrFail();
        $alert = Alert::where('signal_id', $signal->id)->firstOrFail();

        $this->assertStringContains('T-999', $alert->ai_message);
        $this->assertStringContains('Pedro Ramos', $alert->ai_message);
    }

    public function test_immediate_notification_decision_has_dedupe_key(): void
    {
        $this->configureRule('notify_immediate');
        $this->mockContactResolver();

        $eventId = (string) Str::uuid();

        SafetySignal::create($this->safetySignalAttributes([
            'samsara_event_id' => $eventId,
        ]));

        Bus::assertDispatched(SendNotificationJob::class, function (SendNotificationJob $job) use ($eventId) {
            $decision = $this->getJobDecision($job);
            return $decision['dedupe_key'] === "immediate-{$eventId}";
        });
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Mock ContactResolver to return a default monitoring contact.
     */
    private function mockContactResolver(): void
    {
        $this->app->bind(ContactResolver::class, function () {
            $resolver = \Mockery::mock(ContactResolver::class);
            $resolver->shouldReceive('resolve')
                ->andReturn([
                    'monitoring_team' => [
                        'name' => 'Centro de Monitoreo',
                        'role' => 'Monitoreo',
                        'type' => 'monitoring_team',
                        'phone' => '+5211234567890',
                        'whatsapp' => '+5211234567890',
                        'email' => null,
                        'priority' => 1,
                    ],
                ]);
            return $resolver;
        });
    }

    /**
     * Extract the notification decision array from a dispatched SendNotificationJob.
     */
    private function getJobDecision(SendNotificationJob $job): array
    {
        return $job->decision;
    }

    /**
     * Assert that a string contains the given substring.
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertStringContainsString($needle, $haystack);
    }
}
