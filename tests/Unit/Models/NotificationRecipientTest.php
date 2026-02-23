<?php

namespace Tests\Unit\Models;

use App\Models\NotificationDecision;
use App\Models\NotificationRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class NotificationRecipientTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    private function createDecision(): NotificationDecision
    {
        ['alert' => $alert] = $this->createFullAlert($this->company);

        return NotificationDecision::factory()->create([
            'alert_id' => $alert->id,
        ]);
    }

    public function test_decision_relationship(): void
    {
        $decision = $this->createDecision();

        $recipient = NotificationRecipient::create([
            'notification_decision_id' => $decision->id,
            'recipient_type' => 'operator',
            'phone' => '+5215551111111',
            'priority' => 1,
        ]);

        $this->assertTrue($recipient->decision->is($decision));
    }

    public function test_scope_of_type(): void
    {
        $decision = $this->createDecision();

        NotificationRecipient::create([
            'notification_decision_id' => $decision->id,
            'recipient_type' => 'operator',
            'phone' => '+5215551111111',
            'priority' => 1,
        ]);
        NotificationRecipient::create([
            'notification_decision_id' => $decision->id,
            'recipient_type' => 'supervisor',
            'phone' => '+5215552222222',
            'priority' => 2,
        ]);

        $operators = NotificationRecipient::ofType('operator')->get();
        $this->assertCount(1, $operators);
        $this->assertEquals('operator', $operators->first()->recipient_type);
    }

    public function test_scope_with_phone(): void
    {
        $decision = $this->createDecision();

        NotificationRecipient::create([
            'notification_decision_id' => $decision->id,
            'recipient_type' => 'operator',
            'phone' => '+5215551111111',
            'priority' => 1,
        ]);
        NotificationRecipient::create([
            'notification_decision_id' => $decision->id,
            'recipient_type' => 'supervisor',
            'phone' => null,
            'whatsapp' => '+5215552222222',
            'priority' => 2,
        ]);

        $withPhone = NotificationRecipient::withPhone()->get();
        $this->assertCount(1, $withPhone);
        $this->assertEquals('operator', $withPhone->first()->recipient_type);
    }

    public function test_scope_with_whatsapp(): void
    {
        $decision = $this->createDecision();

        NotificationRecipient::create([
            'notification_decision_id' => $decision->id,
            'recipient_type' => 'operator',
            'phone' => '+5215551111111',
            'whatsapp' => null,
            'priority' => 1,
        ]);
        NotificationRecipient::create([
            'notification_decision_id' => $decision->id,
            'recipient_type' => 'supervisor',
            'phone' => null,
            'whatsapp' => '+5215552222222',
            'priority' => 2,
        ]);

        $withWhatsapp = NotificationRecipient::withWhatsapp()->get();
        $this->assertCount(1, $withWhatsapp);
        $this->assertEquals('supervisor', $withWhatsapp->first()->recipient_type);
    }

    public function test_get_type_label_for_each_type(): void
    {
        $decision = $this->createDecision();

        $labels = [
            NotificationRecipient::TYPE_OPERATOR => 'Operador',
            NotificationRecipient::TYPE_MONITORING_TEAM => 'Equipo de monitoreo',
            NotificationRecipient::TYPE_SUPERVISOR => 'Supervisor',
            NotificationRecipient::TYPE_EMERGENCY => 'Emergencia',
            NotificationRecipient::TYPE_DISPATCH => 'Despacho',
        ];

        foreach ($labels as $type => $expected) {
            $recipient = NotificationRecipient::create([
                'notification_decision_id' => $decision->id,
                'recipient_type' => $type,
                'phone' => '+5215551111111',
                'priority' => 1,
            ]);
            $this->assertEquals($expected, $recipient->getTypeLabel());
        }

        $unknown = new NotificationRecipient(['recipient_type' => 'custom_type']);
        $this->assertEquals('custom_type', $unknown->getTypeLabel());
    }

    public function test_get_best_contact_number_prefers_phone(): void
    {
        $decision = $this->createDecision();

        $recipient = NotificationRecipient::create([
            'notification_decision_id' => $decision->id,
            'recipient_type' => 'operator',
            'phone' => '+5215551111111',
            'whatsapp' => '+5215552222222',
            'priority' => 1,
        ]);

        $this->assertEquals('+5215551111111', $recipient->getBestContactNumber());
    }

    public function test_get_best_contact_number_falls_back_to_whatsapp(): void
    {
        $decision = $this->createDecision();

        $recipient = NotificationRecipient::create([
            'notification_decision_id' => $decision->id,
            'recipient_type' => 'operator',
            'phone' => null,
            'whatsapp' => '+5215552222222',
            'priority' => 1,
        ]);

        $this->assertEquals('+5215552222222', $recipient->getBestContactNumber());
    }

    public function test_has_contact_method(): void
    {
        $decision = $this->createDecision();

        $withPhone = NotificationRecipient::create([
            'notification_decision_id' => $decision->id,
            'recipient_type' => 'operator',
            'phone' => '+5215551111111',
            'whatsapp' => null,
            'priority' => 1,
        ]);
        $this->assertTrue($withPhone->hasContactMethod());

        $withWhatsapp = NotificationRecipient::create([
            'notification_decision_id' => $decision->id,
            'recipient_type' => 'supervisor',
            'phone' => null,
            'whatsapp' => '+5215552222222',
            'priority' => 2,
        ]);
        $this->assertTrue($withWhatsapp->hasContactMethod());

        $withNeither = NotificationRecipient::create([
            'notification_decision_id' => $decision->id,
            'recipient_type' => 'emergency',
            'phone' => null,
            'whatsapp' => null,
            'priority' => 3,
        ]);
        $this->assertFalse($withNeither->hasContactMethod());
    }

    public function test_auto_sets_created_at(): void
    {
        $decision = $this->createDecision();

        $recipient = NotificationRecipient::create([
            'notification_decision_id' => $decision->id,
            'recipient_type' => 'operator',
            'phone' => '+5215551111111',
            'priority' => 1,
        ]);

        $this->assertNotNull($recipient->created_at);
    }
}
