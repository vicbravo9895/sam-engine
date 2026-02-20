<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RecordUsageEventJob;
use App\Jobs\SendNotificationJob;
use App\Models\Alert;
use App\Models\Contact;
use App\Services\TwilioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;
use Tests\Traits\MocksExternalServices;

class SendNotificationJobTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline, MocksExternalServices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    private function makeDecision(array $overrides = []): array
    {
        return array_merge([
            'should_notify' => true,
            'escalation_level' => 'low',
            'message_text' => 'Alerta de seguridad en vehículo T-001.',
            'call_script' => null,
            'channels_to_use' => ['sms'],
            'recipients' => [
                ['type' => 'monitoring_team', 'phone' => '+5211234567890', 'priority' => 1],
            ],
            'reason' => 'Safety event notification.',
        ], $overrides);
    }

    private function runJob(SendNotificationJob $job): void
    {
        app()->call([$job, 'handle']);
    }

    public function test_sends_sms_notification(): void
    {
        Bus::fake([RecordUsageEventJob::class]);
        $this->mockTwilioAll();

        ['alert' => $alert] = $this->createCompletedAlert($this->company);
        Contact::factory()->monitoringTeam()->forCompany($this->company)->create();

        $decision = $this->makeDecision(['channels_to_use' => ['sms']]);

        $this->runJob(new SendNotificationJob($alert, $decision));

        $this->assertDatabaseHas('notification_results', [
            'alert_id' => $alert->id,
            'channel' => 'sms',
        ]);
    }

    public function test_sends_whatsapp_notification(): void
    {
        Bus::fake([RecordUsageEventJob::class]);
        $this->mockTwilioAll();

        ['alert' => $alert] = $this->createCompletedAlert($this->company);
        Contact::factory()->monitoringTeam()->forCompany($this->company)->create();

        $decision = $this->makeDecision(['channels_to_use' => ['whatsapp']]);

        $this->runJob(new SendNotificationJob($alert, $decision));

        $this->assertDatabaseHas('notification_results', [
            'alert_id' => $alert->id,
            'channel' => 'whatsapp',
        ]);
    }

    public function test_sends_call_notification(): void
    {
        Bus::fake([RecordUsageEventJob::class]);
        $this->mockTwilioAll();

        ['alert' => $alert] = $this->createCompletedAlert($this->company);
        Contact::factory()->monitoringTeam()->forCompany($this->company)->create();

        $decision = $this->makeDecision(['channels_to_use' => ['call']]);

        $this->runJob(new SendNotificationJob($alert, $decision));

        $this->assertDatabaseHas('notification_results', [
            'alert_id' => $alert->id,
            'channel' => 'call',
        ]);
    }

    public function test_skips_when_should_not_notify(): void
    {
        Http::fake();

        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $decision = $this->makeDecision(['should_notify' => false]);

        $this->runJob(new SendNotificationJob($alert, $decision));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('notification_results', [
            'alert_id' => $alert->id,
        ]);
    }

    public function test_respects_channel_company_config(): void
    {
        Bus::fake([RecordUsageEventJob::class]);
        $this->mockTwilioAll();

        $this->company->update([
            'settings' => array_merge($this->company->settings ?? [], [
                'notifications' => ['channels_enabled' => ['sms' => false, 'whatsapp' => true, 'call' => false]],
            ]),
        ]);

        ['alert' => $alert] = $this->createCompletedAlert($this->company);
        Contact::factory()->monitoringTeam()->forCompany($this->company)->create();

        $decision = $this->makeDecision(['channels_to_use' => ['sms', 'whatsapp']]);

        $this->runJob(new SendNotificationJob($alert, $decision));

        $results = $alert->notificationResults;
        $channels = $results->pluck('channel')->toArray();
        $this->assertNotContains('sms', $channels);
    }

    public function test_persists_notification_results(): void
    {
        Bus::fake([RecordUsageEventJob::class]);
        $this->mockTwilioAll();

        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $decision = $this->makeDecision();

        $this->runJob(new SendNotificationJob($alert, $decision));

        $this->assertDatabaseHas('notification_results', [
            'alert_id' => $alert->id,
        ]);
    }

    public function test_handles_panic_button_special_flow(): void
    {
        Bus::fake([RecordUsageEventJob::class]);
        $this->mockTwilioAll();

        ['alert' => $alert] = $this->createCriticalPanicAlert($this->company);
        Contact::factory()->monitoringTeam()->forCompany($this->company)->create();

        $decision = $this->makeDecision([
            'channels_to_use' => ['call'],
            'escalation_level' => 'critical',
        ]);

        $this->runJob(new SendNotificationJob($alert, $decision));

        $this->assertDatabaseHas('notification_results', [
            'alert_id' => $alert->id,
            'channel' => 'call',
        ]);
    }

    public function test_records_usage_events_per_channel(): void
    {
        Bus::fake([RecordUsageEventJob::class]);
        $this->mockTwilioAll();

        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $decision = $this->makeDecision(['channels_to_use' => ['sms']]);

        $this->runJob(new SendNotificationJob($alert, $decision));

        Bus::assertDispatched(RecordUsageEventJob::class);
    }
}
