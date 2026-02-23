<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SendPanicEscalationJob;
use App\Models\Alert;
use App\Models\AlertActivity;
use App\Models\Contact;
use App\Models\NotificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;
use Tests\Traits\MocksExternalServices;

class SendPanicEscalationJobTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline, MocksExternalServices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    private function runJob(SendPanicEscalationJob $job): void
    {
        app()->call([$job, 'handle']);
    }

    public function test_sends_notifications_to_monitoring_team(): void
    {
        Bus::fake();
        $this->mockTwilioAll();

        ['alert' => $alert] = $this->createCriticalPanicAlert($this->company);
        Contact::factory()->monitoringTeam()->forCompany($this->company)->create([
            'phone' => '+5211234567890',
            'phone_whatsapp' => '+5211234567890',
        ]);

        $this->runJob(new SendPanicEscalationJob($alert));

        $this->assertDatabaseHas('notification_results', [
            'alert_id' => $alert->id,
            'recipient_type' => 'monitoring_team',
        ]);
        $this->assertDatabaseHas('alerts', [
            'id' => $alert->id,
            'notification_status' => 'escalated',
        ]);
    }

    public function test_sends_call_to_emergency_contacts(): void
    {
        Bus::fake();
        $this->mockTwilioAll();

        ['alert' => $alert] = $this->createCriticalPanicAlert($this->company);
        Contact::factory()->emergency()->forCompany($this->company)->create([
            'phone' => '+5219876543210',
        ]);

        $this->runJob(new SendPanicEscalationJob($alert));

        $this->assertDatabaseHas('notification_results', [
            'alert_id' => $alert->id,
            'recipient_type' => 'emergency',
            'channel' => 'call',
        ]);
    }

    public function test_sends_whatsapp_and_sms_to_supervisors(): void
    {
        Bus::fake();
        $this->mockTwilioAll();

        ['alert' => $alert] = $this->createCriticalPanicAlert($this->company);
        Contact::factory()->supervisor()->forCompany($this->company)->create([
            'phone' => '+5215555555555',
            'phone_whatsapp' => '+5215555555555',
        ]);

        $this->runJob(new SendPanicEscalationJob($alert));

        $results = NotificationResult::where('alert_id', $alert->id)
            ->where('recipient_type', 'supervisor')
            ->get();
        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    public function test_skips_inactive_contacts(): void
    {
        Bus::fake();
        $this->mockTwilioAll();

        ['alert' => $alert] = $this->createCriticalPanicAlert($this->company);
        Contact::factory()->monitoringTeam()->inactive()->forCompany($this->company)->create([
            'phone' => '+5211111111111',
        ]);
        Contact::factory()->monitoringTeam()->forCompany($this->company)->create([
            'phone' => '+5212222222222',
        ]);

        $this->runJob(new SendPanicEscalationJob($alert));

        $results = NotificationResult::where('alert_id', $alert->id)->get();
        $this->assertGreaterThanOrEqual(1, $results->count());
        $phoneNumbers = $results->pluck('to_number')->toArray();
        $this->assertNotContains('+5211111111111', $phoneNumbers);
    }

    public function test_creates_alert_activity(): void
    {
        Bus::fake();
        $this->mockTwilioAll();

        ['alert' => $alert] = $this->createCriticalPanicAlert($this->company);
        Contact::factory()->monitoringTeam()->forCompany($this->company)->create();

        $this->runJob(new SendPanicEscalationJob($alert));

        $this->assertDatabaseHas('alert_activities', [
            'alert_id' => $alert->id,
            'action' => 'panic_escalation_sent',
        ]);
    }

    public function test_updates_alert_notification_status(): void
    {
        Bus::fake();
        $this->mockTwilioAll();

        ['alert' => $alert] = $this->createCriticalPanicAlert($this->company);
        Contact::factory()->monitoringTeam()->forCompany($this->company)->create();

        $this->runJob(new SendPanicEscalationJob($alert));

        $alert->refresh();
        $this->assertSame('escalated', $alert->notification_status);
        $this->assertNotNull($alert->notification_sent_at);
        $this->assertIsArray($alert->notification_channels);
    }

    public function test_handles_alert_without_contacts(): void
    {
        Bus::fake();
        $this->mockTwilioAll();

        ['alert' => $alert] = $this->createCriticalPanicAlert($this->company);

        $this->runJob(new SendPanicEscalationJob($alert));

        $this->assertDatabaseHas('alerts', [
            'id' => $alert->id,
            'notification_status' => 'escalated',
        ]);
    }

    public function test_handles_alert_with_null_vehicle_and_driver_gracefully(): void
    {
        Bus::fake();
        $this->mockTwilioAll();

        ['alert' => $alert, 'signal' => $signal] = $this->createCriticalPanicAlert($this->company);
        $signal->update(['vehicle_name' => null, 'driver_name' => null]);
        Contact::factory()->monitoringTeam()->forCompany($this->company)->create();

        $this->runJob(new SendPanicEscalationJob($alert->fresh()));

        $this->assertDatabaseHas('alerts', [
            'id' => $alert->id,
            'notification_status' => 'escalated',
        ]);
    }

    public function test_persists_notification_results_with_success(): void
    {
        Bus::fake();
        Http::fake([
            'api.twilio.com/*' => Http::response([
                'sid' => 'SM' . str_repeat('a', 32),
                'status' => 'queued',
            ], 201),
        ]);

        ['alert' => $alert] = $this->createCriticalPanicAlert($this->company);
        Contact::factory()->monitoringTeam()->forCompany($this->company)->create();

        $this->runJob(new SendPanicEscalationJob($alert));

        $results = NotificationResult::where('alert_id', $alert->id)->get();
        $this->assertGreaterThanOrEqual(1, $results->count());
        foreach ($results as $result) {
            $this->assertNotNull($result->channel);
            $this->assertNotNull($result->recipient_type);
        }
    }

    public function test_uses_location_from_payload_when_available(): void
    {
        Bus::fake();
        $this->mockTwilioAll();

        ['alert' => $alert, 'signal' => $signal] = $this->createCriticalPanicAlert($this->company);
        $signal->update([
            'raw_payload' => array_merge($signal->raw_payload ?? [], [
                'data' => [
                    'location' => [
                        'formattedAddress' => 'Av. Reforma 123, CDMX',
                        'latitude' => 19.4326,
                        'longitude' => -99.1332,
                    ],
                ],
            ]),
        ]);
        Contact::factory()->monitoringTeam()->forCompany($this->company)->create();

        $this->runJob(new SendPanicEscalationJob($alert->fresh()));

        $this->assertDatabaseHas('alerts', [
            'id' => $alert->id,
            'notification_status' => 'escalated',
        ]);
    }

    public function test_continues_when_twilio_fails_for_some_requests(): void
    {
        Bus::fake();
        Http::fake([
            'api.twilio.com/*' => Http::sequence()
                ->push(['sid' => 'SM123', 'status' => 'queued'], 201)
                ->push(['message' => 'Invalid number'], 400),
        ]);

        ['alert' => $alert] = $this->createCriticalPanicAlert($this->company);
        Contact::factory()->monitoringTeam()->forCompany($this->company)->create([
            'phone' => '+5211111111111',
            'phone_whatsapp' => '+5211111111111',
        ]);

        $this->runJob(new SendPanicEscalationJob($alert));

        $this->assertDatabaseHas('alerts', [
            'id' => $alert->id,
            'notification_status' => 'escalated',
        ]);
        $results = NotificationResult::where('alert_id', $alert->id)->get();
        $this->assertGreaterThanOrEqual(1, $results->count());
    }
}
