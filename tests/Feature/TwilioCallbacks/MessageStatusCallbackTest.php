<?php

namespace Tests\Feature\TwilioCallbacks;

use App\Jobs\EmitDomainEventJob;
use App\Models\Alert;
use App\Models\Company;
use App\Models\NotificationDeliveryEvent;
use App\Models\NotificationResult;
use App\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class MessageStatusCallbackTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Alert $event;
    private NotificationResult $result;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.twilio.token' => '']);

        $this->company = Company::factory()->create();
        Feature::for($this->company)->activate('ledger-v1');
        Feature::for($this->company)->activate('notifications-v2');

        $signal = Signal::create([
            'company_id' => $this->company->id,
            'source' => 'webhook',
            'samsara_event_id' => 'evt_' . uniqid(),
            'event_type' => 'AlertIncident',
            'event_description' => 'Test event',
            'vehicle_id' => 'veh_1',
            'vehicle_name' => 'T-001',
            'severity' => 'warning',
            'occurred_at' => now(),
            'raw_payload' => [],
        ]);
        $this->event = Alert::create([
            'company_id' => $this->company->id,
            'signal_id' => $signal->id,
            'event_description' => 'Test event',
            'severity' => 'warning',
            'occurred_at' => now(),
            'ai_status' => 'completed',
        ]);

        $this->result = NotificationResult::create([
            'alert_id' => $this->event->id,
            'channel' => 'sms',
            'recipient_type' => 'operator',
            'to_number' => '+5218111111111',
            'success' => true,
            'message_sid' => 'SM' . str_repeat('a', 32),
            'status_current' => 'sent',
            'timestamp_utc' => now(),
        ]);
    }

    public function test_message_delivered_creates_delivery_event_and_updates_status(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $response = $this->post('/api/webhooks/twilio/message-status', [
            'MessageSid' => $this->result->message_sid,
            'MessageStatus' => 'delivered',
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('notification_delivery_events', [
            'notification_result_id' => $this->result->id,
            'provider_sid' => $this->result->message_sid,
            'status' => 'delivered',
        ]);

        $this->result->refresh();
        $this->assertEquals('delivered', $this->result->status_current);

        Bus::assertDispatched(EmitDomainEventJob::class, function (EmitDomainEventJob $job) {
            return $job->eventType === 'notification.delivered'
                && $job->entityType === 'notification';
        });
    }

    public function test_whatsapp_read_creates_delivery_event_and_emits_domain_event(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $this->result->update(['channel' => 'whatsapp', 'status_current' => 'delivered']);

        $response = $this->post('/api/webhooks/twilio/message-status', [
            'MessageSid' => $this->result->message_sid,
            'MessageStatus' => 'read',
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('notification_delivery_events', [
            'notification_result_id' => $this->result->id,
            'status' => 'read',
        ]);

        $this->result->refresh();
        $this->assertEquals('read', $this->result->status_current);

        Bus::assertDispatched(EmitDomainEventJob::class, function (EmitDomainEventJob $job) {
            return $job->eventType === 'notification.read';
        });
    }

    public function test_message_failed_creates_delivery_event_with_error(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $response = $this->post('/api/webhooks/twilio/message-status', [
            'MessageSid' => $this->result->message_sid,
            'MessageStatus' => 'failed',
            'ErrorCode' => '30008',
            'ErrorMessage' => 'Unknown error',
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('notification_delivery_events', [
            'notification_result_id' => $this->result->id,
            'status' => 'failed',
            'error_code' => '30008',
        ]);

        $this->result->refresh();
        $this->assertEquals('failed', $this->result->status_current);

        Bus::assertDispatched(EmitDomainEventJob::class, function (EmitDomainEventJob $job) {
            return $job->eventType === 'notification.failed';
        });
    }

    public function test_unknown_message_sid_returns_204_without_error(): void
    {
        $response = $this->post('/api/webhooks/twilio/message-status', [
            'MessageSid' => 'SM_unknown_sid',
            'MessageStatus' => 'delivered',
        ]);

        $response->assertStatus(204);
        $this->assertDatabaseCount('notification_delivery_events', 0);
    }

    public function test_status_does_not_regress_backward(): void
    {
        $this->result->update(['status_current' => 'delivered']);

        $response = $this->post('/api/webhooks/twilio/message-status', [
            'MessageSid' => $this->result->message_sid,
            'MessageStatus' => 'sent',
        ]);

        $response->assertStatus(204);

        $this->result->refresh();
        $this->assertEquals('delivered', $this->result->status_current);
    }

    public function test_no_delivery_events_when_notifications_v2_inactive(): void
    {
        Feature::for($this->company)->deactivate('notifications-v2');

        $response = $this->post('/api/webhooks/twilio/message-status', [
            'MessageSid' => $this->result->message_sid,
            'MessageStatus' => 'delivered',
        ]);

        $response->assertStatus(204);
        $this->assertDatabaseCount('notification_delivery_events', 0);

        // status_current should still update (not gated)
        $this->result->refresh();
        $this->assertEquals('delivered', $this->result->status_current);
    }
}
