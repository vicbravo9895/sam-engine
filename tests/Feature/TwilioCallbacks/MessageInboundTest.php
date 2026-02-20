<?php

namespace Tests\Feature\TwilioCallbacks;

use App\Jobs\EmitDomainEventJob;
use App\Models\Alert;
use App\Models\Company;
use App\Models\NotificationAck;
use App\Models\NotificationResult;
use App\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class MessageInboundTest extends TestCase
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
            'channel' => 'whatsapp',
            'recipient_type' => 'operator',
            'to_number' => '+5218111111111',
            'success' => true,
            'message_sid' => 'SM' . str_repeat('b', 32),
            'status_current' => 'delivered',
            'timestamp_utc' => now(),
        ]);
    }

    public function test_whatsapp_reply_creates_ack_and_emits_domain_event(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $response = $this->post('/api/webhooks/twilio/message-inbound', [
            'From' => 'whatsapp:+5218111111111',
            'Body' => 'Ok, recibido',
            'MessageSid' => 'SM_inbound_123',
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('notification_acks', [
            'alert_id' => $this->event->id,
            'notification_result_id' => $this->result->id,
            'company_id' => $this->company->id,
            'ack_type' => 'reply',
        ]);

        $ack = NotificationAck::first();
        $this->assertEquals('Ok, recibido', $ack->ack_payload['body']);
        $this->assertEquals('SM_inbound_123', $ack->ack_payload['message_sid']);

        Bus::assertDispatched(EmitDomainEventJob::class, function (EmitDomainEventJob $job) {
            return $job->eventType === 'notification.acked'
                && $job->payload['ack_type'] === 'reply';
        });
    }

    public function test_reply_with_no_matching_notification_returns_204(): void
    {
        $response = $this->post('/api/webhooks/twilio/message-inbound', [
            'From' => 'whatsapp:+5219999999999',
            'Body' => 'Random message',
            'MessageSid' => 'SM_unknown',
        ]);

        $response->assertStatus(204);
        $this->assertDatabaseCount('notification_acks', 0);
    }

    public function test_reply_older_than_24h_is_ignored(): void
    {
        // Move the notification to 25 hours ago
        $this->result->update(['created_at' => now()->subHours(25)]);

        $response = $this->post('/api/webhooks/twilio/message-inbound', [
            'From' => 'whatsapp:+5218111111111',
            'Body' => 'Late reply',
            'MessageSid' => 'SM_late',
        ]);

        $response->assertStatus(204);
        $this->assertDatabaseCount('notification_acks', 0);
    }

    public function test_no_ack_when_notifications_v2_inactive(): void
    {
        Feature::for($this->company)->deactivate('notifications-v2');

        $response = $this->post('/api/webhooks/twilio/message-inbound', [
            'From' => 'whatsapp:+5218111111111',
            'Body' => 'Reply',
            'MessageSid' => 'SM_noflag',
        ]);

        $response->assertStatus(204);
        $this->assertDatabaseCount('notification_acks', 0);
    }

    public function test_creates_activity_entry_for_reply_ack(): void
    {
        $response = $this->post('/api/webhooks/twilio/message-inbound', [
            'From' => 'whatsapp:+5218111111111',
            'Body' => 'Entendido',
            'MessageSid' => 'SM_activity_test',
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('alert_activities', [
            'alert_id' => $this->event->id,
            'action' => 'notification_acked_via_reply',
        ]);
    }
}
