<?php

namespace Tests\Feature\Services;

use App\Models\Alert;
use App\Services\ContactResolver;
use App\Services\MonitorMatrixOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class MonitorMatrixOverrideTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    private MonitorMatrixOverride $override;
    private ContactResolver $contactResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        $this->override = new MonitorMatrixOverride();
        $this->contactResolver = Mockery::mock(ContactResolver::class);
    }

    public function test_returns_null_when_should_notify_is_true(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $result = $this->override->apply(
            $alert,
            ['should_notify' => true, 'escalation_level' => 'none'],
            [],
            $this->contactResolver,
            'Test message'
        );

        $this->assertNull($result);
    }

    public function test_returns_null_when_escalation_level_is_not_none(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $result = $this->override->apply(
            $alert,
            ['should_notify' => false, 'escalation_level' => 'critical'],
            [],
            $this->contactResolver,
            'Test message'
        );

        $this->assertNull($result);
    }

    public function test_returns_null_when_company_has_no_monitor_matrix(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $result = $this->override->apply(
            $alert,
            ['should_notify' => false, 'escalation_level' => 'none'],
            [],
            $this->contactResolver,
            'Test message'
        );

        $this->assertNull($result);
    }

    public function test_returns_null_when_matrix_has_empty_channels(): void
    {
        $this->configureMonitorMatrix(['channels' => [], 'recipients' => ['monitoring']]);
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $result = $this->override->apply(
            $alert,
            ['should_notify' => false, 'escalation_level' => 'none'],
            [],
            $this->contactResolver,
            'Test message'
        );

        $this->assertNull($result);
    }

    public function test_returns_null_when_matrix_has_empty_recipients(): void
    {
        $this->configureMonitorMatrix(['channels' => ['call'], 'recipients' => []]);
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $result = $this->override->apply(
            $alert,
            ['should_notify' => false, 'escalation_level' => 'none'],
            [],
            $this->contactResolver,
            'Test message'
        );

        $this->assertNull($result);
    }

    public function test_returns_null_when_no_contacts_resolved(): void
    {
        $this->configureMonitorMatrix(['channels' => ['call'], 'recipients' => ['monitoring']]);
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $this->contactResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn([]);

        $result = $this->override->apply(
            $alert,
            ['should_notify' => false, 'escalation_level' => 'none'],
            [],
            $this->contactResolver,
            'Test message'
        );

        $this->assertNull($result);
    }

    public function test_applies_override_with_correct_channels_and_recipients(): void
    {
        $this->configureMonitorMatrix(['channels' => ['call', 'sms'], 'recipients' => ['monitoring']]);
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $this->contactResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn([
                'monitoring_team' => [
                    'name' => 'Central Monitor',
                    'phone' => '+5215551234567',
                    'whatsapp' => '+5215551234567',
                ],
            ]);

        $result = $this->override->apply(
            $alert,
            ['should_notify' => false, 'escalation_level' => 'none'],
            [],
            $this->contactResolver,
            'Alerta de monitoreo'
        );

        $this->assertNotNull($result);
        $this->assertTrue($result['should_notify']);
        $this->assertEquals('low', $result['escalation_level']);
        $this->assertEquals(['call', 'sms'], $result['channels_to_use']);
        $this->assertCount(1, $result['recipients']);
        $this->assertEquals('monitoring_team', $result['recipients'][0]['recipient_type']);
    }

    public function test_maps_monitoring_to_monitoring_team(): void
    {
        $this->configureMonitorMatrix(['channels' => ['whatsapp'], 'recipients' => ['monitoring']]);
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $this->contactResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn([
                'monitoring_team' => [
                    'name' => 'Monitor Team',
                    'phone' => '+5215550001111',
                    'whatsapp' => null,
                ],
            ]);

        $result = $this->override->apply(
            $alert,
            ['should_notify' => false, 'escalation_level' => 'none'],
            [],
            $this->contactResolver,
            'Message'
        );

        $this->assertNotNull($result);
        $this->assertEquals('monitoring_team', $result['recipients'][0]['recipient_type']);
    }

    public function test_uses_message_text_from_decision(): void
    {
        $this->configureMonitorMatrix(['channels' => ['sms'], 'recipients' => ['supervisor']]);
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $this->contactResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn([
                'supervisor' => [
                    'name' => 'Supervisor',
                    'phone' => '+5215559998888',
                    'whatsapp' => null,
                ],
            ]);

        $result = $this->override->apply(
            $alert,
            [
                'should_notify' => false,
                'escalation_level' => 'none',
                'message_text' => 'Custom message from decision',
            ],
            [],
            $this->contactResolver,
            'Fallback human message'
        );

        $this->assertNotNull($result);
        $this->assertEquals('Custom message from decision', $result['message_text']);
    }

    public function test_uses_human_message_as_fallback(): void
    {
        $this->configureMonitorMatrix(['channels' => ['sms'], 'recipients' => ['supervisor']]);
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $this->contactResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn([
                'supervisor' => [
                    'name' => 'Supervisor',
                    'phone' => '+5215559998888',
                    'whatsapp' => null,
                ],
            ]);

        $result = $this->override->apply(
            $alert,
            ['should_notify' => false, 'escalation_level' => 'none'],
            [],
            $this->contactResolver,
            'Fallback human message'
        );

        $this->assertNotNull($result);
        $this->assertEquals('Fallback human message', $result['message_text']);
    }

    public function test_dedupe_key_fallback(): void
    {
        $this->configureMonitorMatrix(['channels' => ['sms'], 'recipients' => ['supervisor']]);
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $this->contactResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn([
                'supervisor' => [
                    'name' => 'Supervisor',
                    'phone' => '+5215559998888',
                    'whatsapp' => null,
                ],
            ]);

        $result = $this->override->apply(
            $alert,
            ['should_notify' => false, 'escalation_level' => 'none'],
            [],
            $this->contactResolver,
            'Message'
        );

        $this->assertNotNull($result);
        $this->assertEquals('monitor-' . $alert->id, $result['dedupe_key']);

        // Now test with assessment dedupe_key
        $this->contactResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn([
                'supervisor' => [
                    'name' => 'Supervisor',
                    'phone' => '+5215559998888',
                    'whatsapp' => null,
                ],
            ]);

        $resultWithAssessment = $this->override->apply(
            $alert,
            ['should_notify' => false, 'escalation_level' => 'none'],
            ['dedupe_key' => 'assessment-key-123'],
            $this->contactResolver,
            'Message'
        );

        $this->assertNotNull($resultWithAssessment);
        $this->assertEquals('assessment-key-123', $resultWithAssessment['dedupe_key']);

        // Test with decision dedupe_key (highest priority)
        $this->contactResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn([
                'supervisor' => [
                    'name' => 'Supervisor',
                    'phone' => '+5215559998888',
                    'whatsapp' => null,
                ],
            ]);

        $resultWithDecision = $this->override->apply(
            $alert,
            [
                'should_notify' => false,
                'escalation_level' => 'none',
                'dedupe_key' => 'decision-key-456',
            ],
            ['dedupe_key' => 'assessment-key-123'],
            $this->contactResolver,
            'Message'
        );

        $this->assertNotNull($resultWithDecision);
        $this->assertEquals('decision-key-456', $resultWithDecision['dedupe_key']);
    }

    public function test_filters_invalid_channels(): void
    {
        $this->configureMonitorMatrix(['channels' => ['call', 'email', 'sms', 'pigeon'], 'recipients' => ['monitoring']]);
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $this->contactResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn([
                'monitoring_team' => [
                    'name' => 'Monitor',
                    'phone' => '+5215551111111',
                    'whatsapp' => null,
                ],
            ]);

        $result = $this->override->apply(
            $alert,
            ['should_notify' => false, 'escalation_level' => 'none'],
            [],
            $this->contactResolver,
            'Message'
        );

        $this->assertNotNull($result);
        $this->assertEquals(['call', 'sms'], $result['channels_to_use']);
    }

    private function configureMonitorMatrix(array $matrix): void
    {
        $settings = $this->company->settings ?? [];
        data_set($settings, 'ai_config.escalation_matrix.monitor', $matrix);
        $this->company->update(['settings' => $settings]);
    }
}
