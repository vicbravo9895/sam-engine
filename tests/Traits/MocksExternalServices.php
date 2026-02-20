<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Http;

trait MocksExternalServices
{
    protected function mockAiServiceSuccess(array $overrides = []): void
    {
        $default = [
            'success' => true,
            'assessment' => [
                'verdict' => 'confirmed_violation',
                'likelihood' => 'high',
                'confidence' => 0.92,
                'reasoning' => 'Clear safety violation detected.',
                'recommended_actions' => ['Notify supervisor', 'Review dashcam'],
                'risk_escalation' => 'warn',
                'requires_monitoring' => false,
            ],
            'alert_context' => [
                'alert_kind' => 'safety',
                'triage_notes' => 'Safety event detected.',
                'investigation_strategy' => 'Review vehicle and camera data.',
                'proactive_flag' => false,
                'investigation_plan' => ['Check vehicle stats', 'Review camera footage'],
            ],
            'human_message' => 'Se detectó una violación de seguridad confirmada.',
            'notification_decision' => [
                'should_notify' => true,
                'escalation_level' => 'low',
                'message_text' => 'Alerta de seguridad en vehículo T-001.',
                'call_script' => null,
                'channels_to_use' => ['sms', 'whatsapp'],
                'recipients' => [
                    ['type' => 'monitoring_team', 'priority' => 1],
                ],
                'reason' => 'Critical safety event.',
            ],
            'execution' => [
                'total_tokens' => 1500,
                'cost_estimate' => 0.0045,
                'agents_executed' => ['triage', 'investigator', 'final', 'notification'],
            ],
        ];

        $response = array_merge($default, $overrides);

        Http::fake([
            'api.samsara.com/*' => Http::response(['data' => []], 200),
            '*/alerts/ingest' => Http::response($response, 200),
            '*/alerts/revalidate' => Http::response($response, 200),
        ]);
    }

    protected function mockAiServiceMonitoring(int $nextCheckMinutes = 15): void
    {
        $this->mockAiServiceSuccess([
            'assessment' => [
                'verdict' => 'uncertain',
                'likelihood' => 'medium',
                'confidence' => 0.55,
                'reasoning' => 'Requires further monitoring.',
                'requires_monitoring' => true,
                'monitoring_reason' => 'Insufficient data.',
                'next_check_minutes' => $nextCheckMinutes,
                'risk_escalation' => 'monitor',
            ],
        ]);
    }

    protected function mockAiServiceFailure(int $status = 500): void
    {
        Http::fake([
            'api.samsara.com/*' => Http::response(['data' => []], 200),
            '*/alerts/ingest' => Http::response(['error' => 'Internal server error'], $status),
            '*/alerts/revalidate' => Http::response(['error' => 'Internal server error'], $status),
        ]);
    }

    protected function mockTwilioSms(): void
    {
        Http::fake([
            'api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response([
                'sid' => 'SM' . str_repeat('a', 32),
                'status' => 'queued',
            ], 201),
        ]);
    }

    protected function mockTwilioCall(): void
    {
        Http::fake([
            'api.twilio.com/2010-04-01/Accounts/*/Calls.json' => Http::response([
                'sid' => 'CA' . str_repeat('a', 32),
                'status' => 'queued',
            ], 201),
        ]);
    }

    protected function mockTwilioAll(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response([
                'sid' => 'SM' . str_repeat('a', 32),
                'status' => 'queued',
            ], 201),
        ]);
    }

    protected function mockSamsaraApi(): void
    {
        Http::fake([
            'api.samsara.com/*' => Http::response([
                'data' => [],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);
    }

    protected function mockAllExternalServices(): void
    {
        $this->mockAiServiceSuccess();
        $this->mockTwilioAll();
        $this->mockSamsaraApi();
    }
}
