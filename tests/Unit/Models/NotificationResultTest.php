<?php

namespace Tests\Unit\Models;

use App\Models\Alert;
use App\Models\NotificationDeliveryEvent;
use App\Models\NotificationResult;
use App\Models\Signal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class NotificationResultTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    private function createAlertForNotification(): Alert
    {
        $signal = Signal::factory()->create(['company_id' => $this->company->id]);

        return Alert::factory()->create([
            'company_id' => $this->company->id,
            'signal_id' => $signal->id,
        ]);
    }

    public function test_alert_relationship(): void
    {
        $alert = $this->createAlertForNotification();
        $result = NotificationResult::factory()->create(['alert_id' => $alert->id]);

        $this->assertNotNull($result->alert);
        $this->assertEquals($alert->id, $result->alert->id);
    }

    public function test_delivery_events_relationship(): void
    {
        $alert = $this->createAlertForNotification();
        $result = NotificationResult::factory()->sms()->create(['alert_id' => $alert->id]);

        NotificationDeliveryEvent::create([
            'notification_result_id' => $result->id,
            'provider_sid' => $result->message_sid,
            'status' => 'delivered',
            'raw_callback' => [],
            'received_at' => now(),
        ]);

        $this->assertCount(1, $result->deliveryEvents);
    }

    public function test_scope_channel(): void
    {
        $alert = $this->createAlertForNotification();
        NotificationResult::factory()->sms()->create(['alert_id' => $alert->id]);
        NotificationResult::factory()->call()->create(['alert_id' => $alert->id]);

        $this->assertCount(1, NotificationResult::channel('sms')->get());
        $this->assertCount(1, NotificationResult::channel('call')->get());
    }

    public function test_scope_successful(): void
    {
        $alert = $this->createAlertForNotification();
        NotificationResult::factory()->successful()->create(['alert_id' => $alert->id]);
        NotificationResult::factory()->failed()->create(['alert_id' => $alert->id]);

        $this->assertCount(1, NotificationResult::successful()->get());
    }

    public function test_scope_failed(): void
    {
        $alert = $this->createAlertForNotification();
        NotificationResult::factory()->successful()->create(['alert_id' => $alert->id]);
        NotificationResult::factory()->failed()->create(['alert_id' => $alert->id]);

        $this->assertCount(1, NotificationResult::failed()->get());
    }

    public function test_scope_sms(): void
    {
        $alert = $this->createAlertForNotification();
        NotificationResult::factory()->sms()->create(['alert_id' => $alert->id]);
        NotificationResult::factory()->whatsapp()->create(['alert_id' => $alert->id]);

        $this->assertCount(1, NotificationResult::sms()->get());
    }

    public function test_scope_whatsapp(): void
    {
        $alert = $this->createAlertForNotification();
        NotificationResult::factory()->whatsapp()->create(['alert_id' => $alert->id]);
        NotificationResult::factory()->call()->create(['alert_id' => $alert->id]);

        $this->assertCount(1, NotificationResult::whatsapp()->get());
    }

    public function test_scope_calls(): void
    {
        $alert = $this->createAlertForNotification();
        NotificationResult::factory()->call()->create(['alert_id' => $alert->id]);
        NotificationResult::factory()->sms()->create(['alert_id' => $alert->id]);

        $this->assertCount(1, NotificationResult::calls()->get());
    }

    public function test_get_channel_label_sms(): void
    {
        $result = NotificationResult::factory()->make(['channel' => 'sms']);
        $this->assertEquals('SMS', $result->getChannelLabel());
    }

    public function test_get_channel_label_whatsapp(): void
    {
        $result = NotificationResult::factory()->make(['channel' => 'whatsapp']);
        $this->assertEquals('WhatsApp', $result->getChannelLabel());
    }

    public function test_get_channel_label_call(): void
    {
        $result = NotificationResult::factory()->make(['channel' => 'call']);
        $this->assertEquals('Llamada', $result->getChannelLabel());
    }

    public function test_get_channel_label_unknown_returns_raw_value(): void
    {
        $result = NotificationResult::factory()->make(['channel' => 'email']);
        $this->assertEquals('email', $result->getChannelLabel());
    }

    public function test_get_twilio_sid_returns_call_sid(): void
    {
        $result = NotificationResult::factory()->make([
            'call_sid' => 'CA123abc',
            'message_sid' => null,
        ]);

        $this->assertEquals('CA123abc', $result->getTwilioSid());
    }

    public function test_get_twilio_sid_returns_message_sid_when_no_call_sid(): void
    {
        $result = NotificationResult::factory()->make([
            'call_sid' => null,
            'message_sid' => 'SM456def',
        ]);

        $this->assertEquals('SM456def', $result->getTwilioSid());
    }

    public function test_get_twilio_sid_prefers_call_sid_over_message_sid(): void
    {
        $result = NotificationResult::factory()->make([
            'call_sid' => 'CA123',
            'message_sid' => 'SM456',
        ]);

        $this->assertEquals('CA123', $result->getTwilioSid());
    }

    public function test_get_twilio_sid_returns_null_when_none_set(): void
    {
        $result = NotificationResult::factory()->make([
            'call_sid' => null,
            'message_sid' => null,
        ]);

        $this->assertNull($result->getTwilioSid());
    }

    public function test_is_call_returns_true_for_call_channel(): void
    {
        $result = NotificationResult::factory()->make(['channel' => 'call']);
        $this->assertTrue($result->isCall());
    }

    public function test_is_call_returns_false_for_sms(): void
    {
        $result = NotificationResult::factory()->make(['channel' => 'sms']);
        $this->assertFalse($result->isCall());
    }

    public function test_create_success_sets_fields(): void
    {
        $alert = $this->createAlertForNotification();

        $result = NotificationResult::createSuccess([
            'alert_id' => $alert->id,
            'channel' => 'sms',
            'to_number' => '+5215512345678',
            'message_sid' => 'SM123',
        ]);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->timestamp_utc);
        $this->assertEquals('sms', $result->channel);
    }

    public function test_create_failure_sets_fields(): void
    {
        $alert = $this->createAlertForNotification();

        $result = NotificationResult::createFailure(
            [
                'alert_id' => $alert->id,
                'channel' => 'whatsapp',
                'to_number' => '+5215512345678',
            ],
            'Twilio error: 21211'
        );

        $this->assertFalse($result->success);
        $this->assertEquals('Twilio error: 21211', $result->error);
        $this->assertNotNull($result->timestamp_utc);
    }

    public function test_update_status_from_callback_advances_forward(): void
    {
        $alert = $this->createAlertForNotification();
        $result = NotificationResult::factory()->sms()->create([
            'alert_id' => $alert->id,
            'status_current' => 'queued',
        ]);

        $this->assertTrue($result->updateStatusFromCallback('sent'));
        $this->assertEquals('sent', $result->fresh()->status_current);
    }

    public function test_update_status_from_callback_ignores_stale_update(): void
    {
        $alert = $this->createAlertForNotification();
        $result = NotificationResult::factory()->sms()->create([
            'alert_id' => $alert->id,
            'status_current' => 'delivered',
        ]);

        $this->assertFalse($result->updateStatusFromCallback('sent'));
        $this->assertEquals('delivered', $result->fresh()->status_current);
    }

    public function test_update_status_from_callback_allows_failed_even_from_sent(): void
    {
        $alert = $this->createAlertForNotification();
        $result = NotificationResult::factory()->sms()->create([
            'alert_id' => $alert->id,
            'status_current' => 'sent',
        ]);

        $this->assertTrue($result->updateStatusFromCallback('failed'));
        $this->assertEquals('failed', $result->fresh()->status_current);
    }

    public function test_update_status_from_callback_allows_undelivered(): void
    {
        $alert = $this->createAlertForNotification();
        $result = NotificationResult::factory()->sms()->create([
            'alert_id' => $alert->id,
            'status_current' => 'sending',
        ]);

        $this->assertTrue($result->updateStatusFromCallback('undelivered'));
        $this->assertEquals('undelivered', $result->fresh()->status_current);
    }

    public function test_update_status_from_callback_unknown_status_ignored(): void
    {
        $alert = $this->createAlertForNotification();
        $result = NotificationResult::factory()->sms()->create([
            'alert_id' => $alert->id,
            'status_current' => 'delivered',
        ]);

        $this->assertFalse($result->updateStatusFromCallback('unknown_status'));
        $this->assertEquals('delivered', $result->fresh()->status_current);
    }

    public function test_created_at_is_auto_set(): void
    {
        Carbon::setTestNow('2026-02-20 08:00:00');

        $alert = $this->createAlertForNotification();
        $result = NotificationResult::factory()->create([
            'alert_id' => $alert->id,
            'created_at' => null,
        ]);

        $this->assertNotNull($result->created_at);

        Carbon::setTestNow();
    }
}
