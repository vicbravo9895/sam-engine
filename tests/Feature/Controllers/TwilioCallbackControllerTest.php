<?php

namespace Tests\Feature\Controllers;

use App\Http\Middleware\VerifyTwilioSignature;
use App\Jobs\SendPanicEscalationJob;
use App\Models\Alert;
use App\Models\AlertActivity;
use App\Models\NotificationAck;
use App\Models\NotificationDeliveryEvent;
use App\Models\NotificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class TwilioCallbackControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyTwilioSignature::class);
        $this->setUpTenant();
    }

    // ── voiceCallback ────────────────────────────────────────────

    public function test_voice_callback_confirms_panic_with_digit_1(): void
    {
        Bus::fake([SendPanicEscalationJob::class]);

        ['alert' => $alert] = $this->createCriticalPanicAlert($this->company);

        $response = $this->post('/api/webhooks/twilio/voice-callback', [
            'event_id' => $alert->id,
            'Digits' => '1',
            'CallSid' => 'CA_test_confirm',
            'CallStatus' => 'in-progress',
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $this->assertStringContains('Emergencia confirmada', $response->getContent());

        Bus::assertDispatched(SendPanicEscalationJob::class, function ($job) use ($alert) {
            return $job->alert->id === $alert->id;
        });

        $alert->refresh();
        $this->assertEquals('panic_confirmed', $alert->notification_status);
        $this->assertTrue($alert->call_response['is_real_panic']);
        $this->assertEquals('confirmed_panic', $alert->call_response['response_type']);

        $this->assertDatabaseHas('alert_activities', [
            'alert_id' => $alert->id,
            'action' => 'panic_confirmed_by_operator',
        ]);
    }

    public function test_voice_callback_denies_panic_with_digit_2(): void
    {
        Bus::fake([SendPanicEscalationJob::class]);

        ['alert' => $alert] = $this->createCriticalPanicAlert($this->company);

        $response = $this->post('/api/webhooks/twilio/voice-callback', [
            'event_id' => $alert->id,
            'Digits' => '2',
            'CallSid' => 'CA_test_deny',
            'CallStatus' => 'in-progress',
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $this->assertStringContains('activación accidental', $response->getContent());

        Bus::assertNotDispatched(SendPanicEscalationJob::class);

        $alert->refresh();
        $this->assertEquals('false_alarm', $alert->notification_status);
        $this->assertTrue($alert->call_response['is_false_alarm']);
        $this->assertFalse($alert->call_response['is_real_panic']);
        $this->assertEquals('false_alarm', $alert->call_response['response_type']);

        $this->assertDatabaseHas('alert_activities', [
            'alert_id' => $alert->id,
            'action' => 'panic_denied_by_operator',
        ]);
    }

    public function test_voice_callback_handles_no_digits(): void
    {
        Bus::fake([SendPanicEscalationJob::class]);

        ['alert' => $alert] = $this->createCriticalPanicAlert($this->company);

        $response = $this->post('/api/webhooks/twilio/voice-callback', [
            'event_id' => $alert->id,
            'CallSid' => 'CA_test_none',
            'CallStatus' => 'in-progress',
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

        $body = $response->getContent();
        $this->assertStringContains('Opción no válida', $body);
        $this->assertStringContains('Gather', $body);

        Bus::assertNotDispatched(SendPanicEscalationJob::class);

        $alert->refresh();
        $this->assertEquals('operator_no_response', $alert->notification_status);

        $this->assertDatabaseHas('alert_activities', [
            'alert_id' => $alert->id,
            'action' => 'operator_callback_timeout',
        ]);
    }

    public function test_voice_callback_returns_error_for_missing_alert_id(): void
    {
        $response = $this->post('/api/webhooks/twilio/voice-callback', [
            'Digits' => '1',
            'CallSid' => 'CA_test_no_event',
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $this->assertStringContains('evento no identificado', $response->getContent());
    }

    public function test_voice_callback_returns_error_for_nonexistent_alert(): void
    {
        $response = $this->post('/api/webhooks/twilio/voice-callback', [
            'event_id' => 99999,
            'Digits' => '1',
            'CallSid' => 'CA_test_ghost',
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $this->assertStringContains('Evento no encontrado', $response->getContent());
    }

    public function test_voice_callback_creates_ivr_ack_when_notifications_v2_active(): void
    {
        Bus::fake([SendPanicEscalationJob::class]);
        Feature::define('notifications-v2', fn ($scope) => true);

        ['alert' => $alert] = $this->createCriticalPanicAlert($this->company);

        $callSid = 'CA_ivr_ack_test';
        NotificationResult::factory()->call()->create([
            'alert_id' => $alert->id,
            'call_sid' => $callSid,
        ]);

        $this->post('/api/webhooks/twilio/voice-callback', [
            'event_id' => $alert->id,
            'Digits' => '1',
            'CallSid' => $callSid,
            'CallStatus' => 'in-progress',
        ]);

        $this->assertDatabaseHas('notification_acks', [
            'alert_id' => $alert->id,
            'company_id' => $this->company->id,
            'ack_type' => NotificationAck::TYPE_IVR,
        ]);
    }

    // ── voiceStatus ──────────────────────────────────────────────

    public function test_voice_status_updates_alert_status(): void
    {
        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $callSid = 'CA_status_update';
        $alert->update([
            'twilio_call_sid' => $callSid,
            'notification_status' => 'sent',
        ]);

        $response = $this->post('/api/webhooks/twilio/voice-status', [
            'CallSid' => $callSid,
            'CallStatus' => 'no-answer',
        ]);

        $response->assertOk();

        $alert->refresh();
        $this->assertEquals('no-answer', $alert->call_response['call_status']);
        $this->assertEquals('call_no_answer', $alert->notification_status);
    }

    public function test_voice_status_returns_200_for_unknown_call(): void
    {
        $response = $this->post('/api/webhooks/twilio/voice-status', [
            'CallSid' => 'CA_unknown_call',
            'CallStatus' => 'completed',
        ]);

        $response->assertOk();
    }

    public function test_voice_status_does_not_override_terminal_notification_status(): void
    {
        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $callSid = 'CA_completed_call';
        $alert->update([
            'twilio_call_sid' => $callSid,
            'notification_status' => 'sent',
        ]);

        $this->post('/api/webhooks/twilio/voice-status', [
            'CallSid' => $callSid,
            'CallStatus' => 'completed',
        ]);

        $alert->refresh();
        $this->assertEquals('sent', $alert->notification_status);
        $this->assertEquals('completed', $alert->call_response['call_status']);
    }

    public function test_voice_status_creates_delivery_event_when_v2_active(): void
    {
        Feature::define('notifications-v2', fn ($scope) => true);

        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $callSid = 'CA_delivery_event';
        $alert->update(['twilio_call_sid' => $callSid, 'notification_status' => 'sent']);

        $notifResult = NotificationResult::factory()->call()->create([
            'alert_id' => $alert->id,
            'call_sid' => $callSid,
        ]);

        $this->post('/api/webhooks/twilio/voice-status', [
            'CallSid' => $callSid,
            'CallStatus' => 'completed',
        ]);

        $this->assertDatabaseHas('notification_delivery_events', [
            'notification_result_id' => $notifResult->id,
            'provider_sid' => $callSid,
            'status' => 'delivered',
        ]);
    }

    // ── messageStatus ────────────────────────────────────────────

    public function test_message_status_updates_notification_result(): void
    {
        Feature::define('notifications-v2', fn ($scope) => true);

        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $messageSid = 'SM_test_delivered';
        $notifResult = NotificationResult::factory()->sms()->create([
            'alert_id' => $alert->id,
            'message_sid' => $messageSid,
            'status_current' => 'sent',
        ]);

        $response = $this->post('/api/webhooks/twilio/message-status', [
            'MessageSid' => $messageSid,
            'MessageStatus' => 'delivered',
        ]);

        $response->assertNoContent();

        $notifResult->refresh();
        $this->assertEquals('delivered', $notifResult->status_current);

        $this->assertDatabaseHas('notification_delivery_events', [
            'notification_result_id' => $notifResult->id,
            'provider_sid' => $messageSid,
            'status' => 'delivered',
        ]);
    }

    public function test_message_status_returns_204_for_unknown_sid(): void
    {
        $response = $this->post('/api/webhooks/twilio/message-status', [
            'MessageSid' => 'SM_nonexistent',
            'MessageStatus' => 'delivered',
        ]);

        $response->assertNoContent();
    }

    public function test_message_status_returns_204_for_empty_params(): void
    {
        $response = $this->post('/api/webhooks/twilio/message-status', []);

        $response->assertNoContent();
    }

    public function test_message_status_records_error_code(): void
    {
        Feature::define('notifications-v2', fn ($scope) => true);

        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $messageSid = 'SM_test_failed';
        $notifResult = NotificationResult::factory()->sms()->create([
            'alert_id' => $alert->id,
            'message_sid' => $messageSid,
            'status_current' => 'sent',
        ]);

        $this->post('/api/webhooks/twilio/message-status', [
            'MessageSid' => $messageSid,
            'MessageStatus' => 'failed',
            'ErrorCode' => '30003',
            'ErrorMessage' => 'Unreachable',
        ]);

        $this->assertDatabaseHas('notification_delivery_events', [
            'notification_result_id' => $notifResult->id,
            'status' => 'failed',
            'error_code' => '30003',
            'error_message' => 'Unreachable',
        ]);
    }

    // ── messageInbound ───────────────────────────────────────────

    public function test_message_inbound_creates_ack_for_matching_notification(): void
    {
        Feature::define('notifications-v2', fn ($scope) => true);

        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $toNumber = '+5215551234567';
        NotificationResult::factory()->whatsapp()->create([
            'alert_id' => $alert->id,
            'to_number' => "whatsapp:{$toNumber}",
            'success' => true,
            'created_at' => now()->subMinutes(30),
        ]);

        $response = $this->post('/api/webhooks/twilio/message-inbound', [
            'From' => "whatsapp:{$toNumber}",
            'Body' => 'Recibido, gracias.',
            'MessageSid' => 'SM_inbound_test',
        ]);

        $response->assertNoContent();

        $this->assertDatabaseHas('notification_acks', [
            'alert_id' => $alert->id,
            'company_id' => $this->company->id,
            'ack_type' => NotificationAck::TYPE_REPLY,
        ]);

        $ack = NotificationAck::where('alert_id', $alert->id)->first();
        $this->assertEquals('Recibido, gracias.', $ack->ack_payload['body']);
        $this->assertEquals("whatsapp:{$toNumber}", $ack->ack_payload['from']);
    }

    public function test_message_inbound_returns_204_for_unknown_sender(): void
    {
        Feature::define('notifications-v2', fn ($scope) => true);

        $response = $this->post('/api/webhooks/twilio/message-inbound', [
            'From' => 'whatsapp:+5219999999999',
            'Body' => 'Hello',
            'MessageSid' => 'SM_unknown_sender',
        ]);

        $response->assertNoContent();
        $this->assertDatabaseCount('notification_acks', 0);
    }

    public function test_message_inbound_returns_204_when_no_from(): void
    {
        $response = $this->post('/api/webhooks/twilio/message-inbound', [
            'Body' => 'Hello',
            'MessageSid' => 'SM_no_from',
        ]);

        $response->assertNoContent();
    }

    public function test_message_inbound_ignores_old_notifications(): void
    {
        Feature::define('notifications-v2', fn ($scope) => true);

        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $toNumber = '+5215559876543';
        NotificationResult::factory()->whatsapp()->create([
            'alert_id' => $alert->id,
            'to_number' => "whatsapp:{$toNumber}",
            'success' => true,
            'created_at' => now()->subHours(25),
        ]);

        $response = $this->post('/api/webhooks/twilio/message-inbound', [
            'From' => "whatsapp:{$toNumber}",
            'Body' => 'Reply',
            'MessageSid' => 'SM_old_notif',
        ]);

        $response->assertNoContent();
        $this->assertDatabaseCount('notification_acks', 0);
    }

    // ── Private helpers ──────────────────────────────────────────

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'."
        );
    }
}
