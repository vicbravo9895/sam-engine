<?php

namespace Tests\Feature\TwilioCallbacks;

use App\Jobs\EmitDomainEventJob;
use App\Models\Alert;
use App\Models\Company;
use App\Models\NotificationAck;
use App\Models\NotificationDeliveryEvent;
use App\Models\NotificationResult;
use App\Models\Signal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class NotificationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * End-to-end lifecycle: sent → delivered → read → UI ACK.
     * Verifies the full chain produces delivery events, acks, and domain events.
     */
    public function test_full_notification_lifecycle(): void
    {
        Bus::fake([EmitDomainEventJob::class]);
        config(['services.twilio.token' => '']);

        $company = Company::factory()->create();
        Feature::for($company)->activate('ledger-v1');
        Feature::for($company)->activate('notifications-v2');

        $signal = Signal::create([
            'company_id' => $company->id,
            'source' => 'webhook',
            'samsara_event_id' => 'evt_lifecycle_' . uniqid(),
            'event_type' => 'AlertIncident',
            'event_description' => 'Panic Button',
            'vehicle_id' => 'veh_lc',
            'vehicle_name' => 'T-LC',
            'severity' => 'critical',
            'occurred_at' => now(),
            'raw_payload' => [],
        ]);
        $event = Alert::create([
            'company_id' => $company->id,
            'signal_id' => $signal->id,
            'event_description' => 'Panic Button',
            'severity' => 'critical',
            'occurred_at' => now(),
            'ai_status' => 'completed',
        ]);

        $messageSid = 'SM' . str_repeat('c', 32);

        $result = NotificationResult::create([
            'alert_id' => $event->id,
            'channel' => 'whatsapp',
            'recipient_type' => 'monitoring_team',
            'to_number' => '+5218112223333',
            'success' => true,
            'message_sid' => $messageSid,
            'status_current' => 'sent',
            'timestamp_utc' => now(),
        ]);

        // 1. Delivered callback
        $this->post('/api/webhooks/twilio/message-status', [
            'MessageSid' => $messageSid,
            'MessageStatus' => 'delivered',
        ])->assertStatus(204);

        $result->refresh();
        $this->assertEquals('delivered', $result->status_current);

        // 2. Read callback (WhatsApp)
        $this->post('/api/webhooks/twilio/message-status', [
            'MessageSid' => $messageSid,
            'MessageStatus' => 'read',
        ])->assertStatus(204);

        $result->refresh();
        $this->assertEquals('read', $result->status_current);

        // Verify delivery events chain
        $deliveryEvents = NotificationDeliveryEvent::where('notification_result_id', $result->id)
            ->orderBy('received_at')
            ->get();

        $this->assertCount(2, $deliveryEvents);
        $this->assertEquals('delivered', $deliveryEvents[0]->status);
        $this->assertEquals('read', $deliveryEvents[1]->status);

        // 3. UI ACK
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        $ackResponse = $this->postJson("/api/alerts/{$event->id}/ack");
        $ackResponse->assertOk();
        $ackResponse->assertJson(['success' => true]);

        $this->assertDatabaseHas('notification_acks', [
            'alert_id' => $event->id,
            'ack_type' => 'ui',
            'ack_by_user_id' => $user->id,
        ]);

        // Verify domain events were dispatched for each step
        Bus::assertDispatched(EmitDomainEventJob::class, fn (EmitDomainEventJob $j) => $j->eventType === 'notification.delivered');
        Bus::assertDispatched(EmitDomainEventJob::class, fn (EmitDomainEventJob $j) => $j->eventType === 'notification.read');
        Bus::assertDispatched(EmitDomainEventJob::class, fn (EmitDomainEventJob $j) => $j->eventType === 'notification.acked');
    }

    public function test_voice_callback_creates_ivr_ack(): void
    {
        config(['services.twilio.token' => '']);
        $company = Company::factory()->create();
        Feature::for($company)->activate('notifications-v2');

        $signal = Signal::create([
            'company_id' => $company->id,
            'source' => 'webhook',
            'samsara_event_id' => 'evt_ivr_' . uniqid(),
            'event_type' => 'AlertIncident',
            'event_description' => 'Panic Button',
            'vehicle_id' => 'veh_ivr',
            'vehicle_name' => 'T-IVR',
            'severity' => 'critical',
            'occurred_at' => now(),
            'raw_payload' => [],
        ]);
        $event = Alert::create([
            'company_id' => $company->id,
            'signal_id' => $signal->id,
            'event_description' => 'Panic Button',
            'severity' => 'critical',
            'occurred_at' => now(),
            'ai_status' => 'completed',
        ]);

        $callSid = 'CA' . str_repeat('d', 32);

        NotificationResult::create([
            'alert_id' => $event->id,
            'channel' => 'call',
            'recipient_type' => 'operator',
            'to_number' => '+5218114445555',
            'success' => true,
            'call_sid' => $callSid,
            'status_current' => 'sent',
            'timestamp_utc' => now(),
        ]);

        $event->update(['twilio_call_sid' => $callSid]);

        $response = $this->post('/api/webhooks/twilio/voice-callback?' . http_build_query(['event_id' => $event->id]), [
            'Digits' => '1',
            'CallSid' => $callSid,
            'CallStatus' => 'in-progress',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');

        $this->assertDatabaseHas('notification_acks', [
            'alert_id' => $event->id,
            'ack_type' => 'ivr',
        ]);

        $ack = NotificationAck::first();
        $this->assertEquals('confirmed_panic', $ack->ack_payload['response_type']);
    }

    public function test_voice_status_creates_delivery_event(): void
    {
        config(['services.twilio.token' => '']);
        $company = Company::factory()->create();
        Feature::for($company)->activate('notifications-v2');

        $signal = Signal::create([
            'company_id' => $company->id,
            'source' => 'webhook',
            'samsara_event_id' => 'evt_vs_' . uniqid(),
            'event_type' => 'AlertIncident',
            'event_description' => 'Test',
            'vehicle_id' => 'veh_vs',
            'vehicle_name' => 'T-VS',
            'severity' => 'warning',
            'occurred_at' => now(),
            'raw_payload' => [],
        ]);
        $event = Alert::create([
            'company_id' => $company->id,
            'signal_id' => $signal->id,
            'event_description' => 'Test',
            'severity' => 'warning',
            'occurred_at' => now(),
            'ai_status' => 'completed',
            'notification_status' => 'sent',
        ]);

        $callSid = 'CA' . str_repeat('e', 32);

        NotificationResult::create([
            'alert_id' => $event->id,
            'channel' => 'call',
            'recipient_type' => 'operator',
            'to_number' => '+5218116667777',
            'success' => true,
            'call_sid' => $callSid,
            'status_current' => 'sent',
            'timestamp_utc' => now(),
        ]);

        $event->update(['twilio_call_sid' => $callSid]);

        $response = $this->post('/api/webhooks/twilio/voice-status', [
            'CallSid' => $callSid,
            'CallStatus' => 'completed',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('notification_delivery_events', [
            'provider_sid' => $callSid,
            'status' => 'delivered',
        ]);
    }

    public function test_duplicate_ui_ack_is_idempotent(): void
    {
        config(['services.twilio.token' => '']);
        $company = Company::factory()->create();
        Feature::for($company)->activate('notifications-v2');

        $signal = Signal::create([
            'company_id' => $company->id,
            'source' => 'webhook',
            'samsara_event_id' => 'evt_dup_' . uniqid(),
            'event_type' => 'AlertIncident',
            'event_description' => 'Test',
            'vehicle_id' => 'veh_dup',
            'vehicle_name' => 'T-DUP',
            'severity' => 'info',
            'occurred_at' => now(),
            'raw_payload' => [],
        ]);
        $event = Alert::create([
            'company_id' => $company->id,
            'signal_id' => $signal->id,
            'event_description' => 'Test',
            'severity' => 'info',
            'occurred_at' => now(),
            'ai_status' => 'completed',
        ]);

        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        $first = $this->postJson("/api/alerts/{$event->id}/ack");
        $first->assertOk();

        $second = $this->postJson("/api/alerts/{$event->id}/ack");
        $second->assertOk();
        $second->assertJson(['message' => 'Ya confirmaste esta alerta']);

        $this->assertDatabaseCount('notification_acks', 1);
    }
}
