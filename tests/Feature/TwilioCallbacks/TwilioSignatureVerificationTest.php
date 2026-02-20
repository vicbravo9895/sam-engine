<?php

namespace Tests\Feature\TwilioCallbacks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwilioSignatureVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function twilioSignature(string $url, array $params, string $authToken): string
    {
        ksort($params);
        $data = $url;
        foreach ($params as $key => $value) {
            $data .= $key . $value;
        }

        return base64_encode(hash_hmac('sha1', $data, $authToken, true));
    }

    public function test_rejects_request_without_signature(): void
    {
        config(['services.twilio.token' => 'test_auth_token']);

        $response = $this->post('/api/webhooks/twilio/message-status', [
            'MessageSid' => 'SM_test',
            'MessageStatus' => 'delivered',
        ]);

        $response->assertStatus(401);
    }

    public function test_rejects_request_with_invalid_signature(): void
    {
        config(['services.twilio.token' => 'test_auth_token']);

        $response = $this->post('/api/webhooks/twilio/message-status', [
            'MessageSid' => 'SM_test',
            'MessageStatus' => 'delivered',
        ], [
            'X-Twilio-Signature' => 'invalid_signature_value',
        ]);

        $response->assertStatus(401);
    }

    public function test_accepts_request_with_valid_signature(): void
    {
        $authToken = 'test_auth_token_123';
        config(['services.twilio.token' => $authToken]);

        $params = [
            'MessageSid' => 'SM_test_valid',
            'MessageStatus' => 'delivered',
        ];

        $url = url('/api/webhooks/twilio/message-status');
        $signature = $this->twilioSignature($url, $params, $authToken);

        $response = $this->post('/api/webhooks/twilio/message-status', $params, [
            'X-Twilio-Signature' => $signature,
        ]);

        // 204 = processed (message SID won't match anything, which is fine)
        $response->assertStatus(204);
    }

    public function test_skips_verification_when_no_token_in_non_production(): void
    {
        config(['services.twilio.token' => '']);

        $response = $this->post('/api/webhooks/twilio/message-status', [
            'MessageSid' => 'SM_notoken',
            'MessageStatus' => 'delivered',
        ]);

        // Should pass through middleware without 401
        $response->assertStatus(204);
    }
}
