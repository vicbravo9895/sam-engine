<?php

namespace Tests\Feature\Services;

use App\Services\TwilioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwilioServiceTest extends TestCase
{
    use RefreshDatabase;

    private function configuredService(): TwilioService
    {
        config([
            'services.twilio.sid' => 'AC_test_sid_123',
            'services.twilio.token' => 'test_auth_token',
            'services.twilio.from' => '+12025551234',
            'services.twilio.whatsapp' => 'whatsapp:+12025551234',
            'services.twilio.callback_url' => 'https://example.com/api/webhooks/twilio',
        ]);

        return new TwilioService();
    }

    public function test_sends_sms(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response([
                'sid' => 'SM_test_sid',
                'status' => 'queued',
            ], 201),
        ]);

        $service = $this->configuredService();
        $result = $service->sendSms('+5218117658890', 'Test SMS message');

        $this->assertTrue($result['success']);
        $this->assertEquals('sms', $result['channel']);
        $this->assertNotNull($result['sid']);

        Http::assertSentCount(1);
    }

    public function test_sends_whatsapp_template(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response([
                'sid' => 'SM_wa_test_sid',
                'status' => 'queued',
            ], 201),
        ]);

        $service = $this->configuredService();
        $result = $service->sendWhatsappTemplate(
            '+5218117658890',
            TwilioService::TEMPLATE_SAFETY_ALERT,
            ['1' => 'T-001', '2' => 'Frenado brusco']
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('whatsapp_template', $result['channel']);
    }

    public function test_makes_call_with_twiml(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response([
                'sid' => 'CA_test_sid',
                'status' => 'queued',
            ], 201),
        ]);

        $service = $this->configuredService();
        $result = $service->makeCall('+5218117658890', 'Alerta de seguridad en vehículo.');

        $this->assertTrue($result['success']);
        $this->assertEquals('call', $result['channel']);
        $this->assertNotNull($result['sid']);
    }

    public function test_panic_call_with_callback(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response([
                'sid' => 'CA_panic_test',
                'status' => 'queued',
            ], 201),
        ]);

        $service = $this->configuredService();
        $result = $service->makePanicCallWithCallback('+5218117658890', 'T-001', 42);

        $this->assertTrue($result['success']);
        $this->assertEquals('call', $result['channel']);
        $this->assertEquals(42, $result['event_id']);
    }

    public function test_phone_formatting_mexico(): void
    {
        $service = $this->configuredService();

        $this->assertEquals('+5218117658890', $service->formatPhoneForWhatsapp('+528117658890'));
        $this->assertEquals('+5218117658890', $service->formatPhoneForWhatsapp('+5218117658890'));
        $this->assertEquals('+14155551234', $service->formatPhoneForWhatsapp('+14155551234'));
    }

    public function test_is_configured_check(): void
    {
        config(['services.twilio.sid' => '', 'services.twilio.token' => '']);
        $service = new TwilioService();
        $this->assertFalse($service->isConfigured());

        $service = $this->configuredService();
        $this->assertTrue($service->isConfigured());
    }

    public function test_handles_api_error(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response([
                'message' => 'The "To" number is not a valid phone number.',
            ], 400),
        ]);

        $service = $this->configuredService();
        $result = $service->sendSms('+invalid', 'Test message');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
    }

    public function test_template_selection_for_events(): void
    {
        $this->assertEquals(
            TwilioService::TEMPLATE_EMERGENCY_ALERT,
            TwilioService::getTemplateForEvent('panic_button')
        );

        $this->assertEquals(
            TwilioService::TEMPLATE_SAFETY_ALERT,
            TwilioService::getTemplateForEvent('harsh_braking')
        );

        $this->assertEquals(
            TwilioService::TEMPLATE_FLEET_ALERT,
            TwilioService::getTemplateForEvent('generic_alert')
        );

        $this->assertEquals(
            TwilioService::TEMPLATE_ESCALATION_MONITORING,
            TwilioService::getTemplateForEvent('anything', 'escalation')
        );
    }

    public function test_not_configured_returns_failure(): void
    {
        config(['services.twilio.sid' => '', 'services.twilio.token' => '']);
        $service = new TwilioService();

        $result = $service->sendSms('+5218117658890', 'Test');
        $this->assertFalse($result['success']);
        $this->assertTrue($result['simulated'] ?? false);
    }
}
