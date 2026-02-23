<?php

namespace Tests\Unit\Models;

use App\Models\Alert;
use App\Models\NotificationDecision;
use App\Models\NotificationRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class NotificationDecisionTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_alert_relationship(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $decision = NotificationDecision::factory()->create([
            'alert_id' => $alert->id,
        ]);

        $this->assertTrue($decision->alert->is($alert));
    }

    public function test_recipients_relationship(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $decision = NotificationDecision::factory()->create([
            'alert_id' => $alert->id,
        ]);

        NotificationRecipient::create([
            'notification_decision_id' => $decision->id,
            'recipient_type' => 'operator',
            'phone' => '+5215551111111',
            'priority' => 2,
        ]);
        NotificationRecipient::create([
            'notification_decision_id' => $decision->id,
            'recipient_type' => 'supervisor',
            'phone' => '+5215552222222',
            'priority' => 1,
        ]);

        $recipients = $decision->recipients;

        $this->assertCount(2, $recipients);
        $this->assertEquals(1, $recipients->first()->priority);
    }

    public function test_normalize_escalation_level_with_valid_values(): void
    {
        foreach (NotificationDecision::ESCALATION_LEVELS as $level) {
            $this->assertEquals($level, NotificationDecision::normalizeEscalationLevel($level));
        }

        $this->assertEquals('critical', NotificationDecision::normalizeEscalationLevel('CRITICAL'));
        $this->assertEquals('high', NotificationDecision::normalizeEscalationLevel(' High '));
    }

    public function test_normalize_escalation_level_with_null(): void
    {
        $this->assertEquals('none', NotificationDecision::normalizeEscalationLevel(null));
        $this->assertEquals('none', NotificationDecision::normalizeEscalationLevel(''));
    }

    public function test_normalize_escalation_level_with_unknown_defaults_to_critical(): void
    {
        $this->assertEquals('critical', NotificationDecision::normalizeEscalationLevel('unknown'));
        $this->assertEquals('critical', NotificationDecision::normalizeEscalationLevel('medium'));
        $this->assertEquals('critical', NotificationDecision::normalizeEscalationLevel('urgent'));
    }

    public function test_scope_should_notify(): void
    {
        ['alert' => $alert1] = $this->createFullAlert($this->company);
        ['alert' => $alert2] = $this->createFullAlert($this->company);

        NotificationDecision::factory()->create([
            'alert_id' => $alert1->id,
            'should_notify' => true,
        ]);
        NotificationDecision::factory()->create([
            'alert_id' => $alert2->id,
            'should_notify' => false,
        ]);

        $notifying = NotificationDecision::shouldNotify()->get();

        $this->assertCount(1, $notifying);
        $this->assertEquals($alert1->id, $notifying->first()->alert_id);
    }

    public function test_scope_escalation(): void
    {
        ['alert' => $alert1] = $this->createFullAlert($this->company);
        ['alert' => $alert2] = $this->createFullAlert($this->company);

        NotificationDecision::factory()->create([
            'alert_id' => $alert1->id,
            'escalation_level' => 'critical',
        ]);
        NotificationDecision::factory()->create([
            'alert_id' => $alert2->id,
            'escalation_level' => 'low',
        ]);

        $critical = NotificationDecision::escalation('critical')->get();
        $this->assertCount(1, $critical);
        $this->assertEquals($alert1->id, $critical->first()->alert_id);
    }

    public function test_scope_critical(): void
    {
        ['alert' => $alert1] = $this->createFullAlert($this->company);
        ['alert' => $alert2] = $this->createFullAlert($this->company);

        NotificationDecision::factory()->create([
            'alert_id' => $alert1->id,
            'escalation_level' => 'critical',
        ]);
        NotificationDecision::factory()->create([
            'alert_id' => $alert2->id,
            'escalation_level' => 'high',
        ]);

        $critical = NotificationDecision::critical()->get();
        $this->assertCount(1, $critical);
    }

    public function test_get_escalation_label(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $labels = [
            'emergency' => 'Emergencia',
            'critical' => 'Crítico',
            'high' => 'Alto',
            'low' => 'Bajo',
            'none' => 'Sin escalación',
        ];

        foreach ($labels as $level => $expected) {
            $decision = NotificationDecision::factory()->create([
                'alert_id' => $alert->id,
                'escalation_level' => $level,
            ]);
            $this->assertEquals($expected, $decision->getEscalationLabel());
        }
    }

    public function test_create_with_recipients(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $decision = NotificationDecision::createWithRecipients(
            [
                'alert_id' => $alert->id,
                'should_notify' => true,
                'escalation_level' => 'high',
                'message_text' => 'Test notification',
                'reason' => 'Safety event',
            ],
            [
                [
                    'recipient_type' => 'operator',
                    'phone' => '+5215551111111',
                    'priority' => 1,
                ],
                [
                    'recipient_type' => 'supervisor',
                    'phone' => '+5215552222222',
                    'priority' => 2,
                ],
            ]
        );

        $this->assertInstanceOf(NotificationDecision::class, $decision);
        $this->assertTrue($decision->exists);
        $this->assertCount(2, $decision->recipients()->get());
    }

    public function test_auto_sets_created_at(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company);

        $decision = NotificationDecision::create([
            'alert_id' => $alert->id,
            'should_notify' => true,
            'escalation_level' => 'low',
            'message_text' => 'Test',
            'reason' => 'Test reason',
        ]);

        $this->assertNotNull($decision->created_at);
    }
}
