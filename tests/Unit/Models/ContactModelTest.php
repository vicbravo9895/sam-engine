<?php

namespace Tests\Unit\Models;

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class ContactModelTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_company_relationship(): void
    {
        $contact = Contact::factory()->forCompany($this->company)->create();

        $this->assertTrue($contact->company->is($this->company));
    }

    public function test_scope_for_company(): void
    {
        Contact::factory()->forCompany($this->company)->count(2)->create();
        [$otherCompany] = $this->createOtherTenant();
        Contact::factory()->forCompany($otherCompany)->create();

        $this->assertCount(2, Contact::forCompany($this->company->id)->get());
    }

    public function test_whatsapp_number_attribute_prefers_phone_whatsapp(): void
    {
        $contact = Contact::factory()->forCompany($this->company)->create([
            'phone' => '+5215551111111',
            'phone_whatsapp' => '+5215552222222',
        ]);

        $this->assertEquals('+5215552222222', $contact->whatsapp_number);
    }

    public function test_whatsapp_number_attribute_falls_back_to_phone(): void
    {
        $contact = Contact::factory()->forCompany($this->company)->create([
            'phone' => '+5215551111111',
            'phone_whatsapp' => null,
        ]);

        $this->assertEquals('+5215551111111', $contact->whatsapp_number);
    }

    public function test_scope_active(): void
    {
        Contact::factory()->forCompany($this->company)->create(['is_active' => true]);
        Contact::factory()->forCompany($this->company)->inactive()->create();

        $this->assertCount(1, Contact::active()->forCompany($this->company->id)->get());
    }

    public function test_scope_default(): void
    {
        Contact::factory()->forCompany($this->company)->default()->create();
        Contact::factory()->forCompany($this->company)->create(['is_default' => false]);

        $this->assertCount(1, Contact::default()->forCompany($this->company->id)->get());
    }

    public function test_scope_of_type(): void
    {
        Contact::factory()->forCompany($this->company)->monitoringTeam()->create();
        Contact::factory()->forCompany($this->company)->supervisor()->create();

        $monitoring = Contact::ofType('monitoring_team')->forCompany($this->company->id)->get();
        $this->assertCount(1, $monitoring);
    }

    public function test_scope_global(): void
    {
        Contact::factory()->forCompany($this->company)->create(['entity_type' => null]);
        Contact::factory()->forCompany($this->company)->create([
            'entity_type' => Contact::ENTITY_VEHICLE,
            'entity_id' => 'v-123',
        ]);

        $this->assertCount(1, Contact::global()->forCompany($this->company->id)->get());
    }

    public function test_scope_for_vehicle(): void
    {
        Contact::factory()->forCompany($this->company)->create([
            'entity_type' => Contact::ENTITY_VEHICLE,
            'entity_id' => 'vehicle-abc',
        ]);
        Contact::factory()->forCompany($this->company)->create([
            'entity_type' => Contact::ENTITY_VEHICLE,
            'entity_id' => 'vehicle-xyz',
        ]);

        $this->assertCount(1, Contact::forVehicle('vehicle-abc')->get());
    }

    public function test_scope_for_driver(): void
    {
        Contact::factory()->forCompany($this->company)->create([
            'entity_type' => Contact::ENTITY_DRIVER,
            'entity_id' => 'driver-123',
        ]);

        $this->assertCount(1, Contact::forDriver('driver-123')->get());
        $this->assertCount(0, Contact::forDriver('driver-999')->get());
    }

    public function test_scope_order_by_priority(): void
    {
        Contact::factory()->forCompany($this->company)->create(['priority' => 1, 'name' => 'Low']);
        Contact::factory()->forCompany($this->company)->create(['priority' => 5, 'name' => 'High']);
        Contact::factory()->forCompany($this->company)->create(['priority' => 3, 'name' => 'Mid']);

        $ordered = Contact::forCompany($this->company->id)->orderByPriority()->get();

        $this->assertEquals('High', $ordered->first()->name);
        $this->assertEquals('Low', $ordered->last()->name);
    }

    public function test_has_phone(): void
    {
        $withPhone = Contact::factory()->forCompany($this->company)->create(['phone' => '+5215551111111']);
        $withoutPhone = Contact::factory()->forCompany($this->company)->create(['phone' => null]);

        $this->assertTrue($withPhone->hasPhone());
        $this->assertFalse($withoutPhone->hasPhone());
    }

    public function test_can_receive_whatsapp(): void
    {
        $withWhatsapp = Contact::factory()->forCompany($this->company)->create([
            'phone_whatsapp' => '+5215552222222',
        ]);
        $withoutWhatsapp = Contact::factory()->forCompany($this->company)->create([
            'phone_whatsapp' => null,
            'phone' => null,
        ]);

        $this->assertTrue($withWhatsapp->canReceiveWhatsapp());
        $this->assertFalse($withoutWhatsapp->canReceiveWhatsapp());
    }

    public function test_can_receive_email(): void
    {
        $withEmail = Contact::factory()->forCompany($this->company)->create(['email' => 'test@example.com']);
        $withoutEmail = Contact::factory()->forCompany($this->company)->create(['email' => null]);

        $this->assertTrue($withEmail->canReceiveEmail());
        $this->assertFalse($withoutEmail->canReceiveEmail());
    }

    public function test_to_notification_payload(): void
    {
        $contact = Contact::factory()->forCompany($this->company)->create([
            'name' => 'Juan Pérez',
            'role' => 'Supervisor',
            'type' => 'supervisor',
            'phone' => '+5215551111111',
            'phone_whatsapp' => '+5215552222222',
            'email' => 'juan@example.com',
            'priority' => 3,
        ]);

        $payload = $contact->toNotificationPayload();

        $this->assertEquals('Juan Pérez', $payload['name']);
        $this->assertEquals('Supervisor', $payload['role']);
        $this->assertEquals('supervisor', $payload['type']);
        $this->assertEquals('+5215551111111', $payload['phone']);
        $this->assertEquals('+5215552222222', $payload['whatsapp']);
        $this->assertEquals('juan@example.com', $payload['email']);
        $this->assertEquals(3, $payload['priority']);
    }

    public function test_get_types(): void
    {
        $types = Contact::getTypes();

        $this->assertArrayHasKey('monitoring_team', $types);
        $this->assertArrayHasKey('supervisor', $types);
        $this->assertArrayHasKey('emergency', $types);
        $this->assertArrayHasKey('dispatch', $types);
        $this->assertArrayNotHasKey('operator', $types);
    }

    public function test_get_type_label(): void
    {
        $contact = Contact::factory()->forCompany($this->company)->supervisor()->create();
        $this->assertEquals('Supervisor', $contact->getTypeLabel());

        $contact->type = 'unknown_type';
        $this->assertEquals('unknown_type', $contact->getTypeLabel());
    }

    public function test_soft_deletes(): void
    {
        $contact = Contact::factory()->forCompany($this->company)->create();

        $contact->delete();

        $this->assertSoftDeleted($contact);
        $this->assertCount(0, Contact::forCompany($this->company->id)->get());
        $this->assertCount(1, Contact::withTrashed()->forCompany($this->company->id)->get());
    }

    public function test_can_receive_whatsapp_falls_back_to_phone(): void
    {
        $contact = Contact::factory()->forCompany($this->company)->create([
            'phone_whatsapp' => null,
            'phone' => '+5215551234567',
        ]);

        $this->assertTrue($contact->canReceiveWhatsapp());
        $this->assertEquals('+5215551234567', $contact->whatsapp_number);
    }

    public function test_notification_preferences_cast_to_array(): void
    {
        $prefs = ['sms' => true, 'whatsapp' => true, 'call' => false];
        $contact = Contact::factory()->forCompany($this->company)->create([
            'notification_preferences' => $prefs,
        ]);

        $contact->refresh();
        $this->assertIsArray($contact->notification_preferences);
        $this->assertTrue($contact->notification_preferences['sms']);
        $this->assertFalse($contact->notification_preferences['call']);
    }

    public function test_notification_preferences_null_by_default(): void
    {
        $contact = Contact::factory()->forCompany($this->company)->create([
            'notification_preferences' => null,
        ]);

        $contact->refresh();
        $this->assertNull($contact->notification_preferences);
    }

    public function test_is_default_casts_to_boolean(): void
    {
        $contact = Contact::factory()->forCompany($this->company)->create(['is_default' => 1]);

        $contact->refresh();
        $this->assertTrue($contact->is_default);
        $this->assertIsBool($contact->is_default);
    }

    public function test_is_active_casts_to_boolean(): void
    {
        $contact = Contact::factory()->forCompany($this->company)->create(['is_active' => 0]);

        $contact->refresh();
        $this->assertFalse($contact->is_active);
        $this->assertIsBool($contact->is_active);
    }

    public function test_priority_casts_to_integer(): void
    {
        $contact = Contact::factory()->forCompany($this->company)->create(['priority' => '7']);

        $contact->refresh();
        $this->assertSame(7, $contact->priority);
    }

    public function test_to_notification_payload_with_null_fields(): void
    {
        $contact = Contact::factory()->forCompany($this->company)->create([
            'phone' => null,
            'phone_whatsapp' => null,
            'email' => null,
        ]);

        $payload = $contact->toNotificationPayload();
        $this->assertNull($payload['phone']);
        $this->assertNull($payload['whatsapp']);
        $this->assertNull($payload['email']);
    }

    public function test_get_type_label_for_all_types(): void
    {
        $labels = [
            Contact::TYPE_MONITORING_TEAM => 'Equipo de Monitoreo',
            Contact::TYPE_SUPERVISOR => 'Supervisor',
            Contact::TYPE_EMERGENCY => 'Emergencia',
            Contact::TYPE_DISPATCH => 'Despacho',
        ];

        foreach ($labels as $type => $expectedLabel) {
            $contact = Contact::factory()->forCompany($this->company)->create(['type' => $type]);
            $this->assertEquals($expectedLabel, $contact->getTypeLabel(), "Failed for type: {$type}");
        }
    }

    public function test_scope_order_by_priority_secondary_sort_by_name(): void
    {
        Contact::factory()->forCompany($this->company)->create(['priority' => 5, 'name' => 'Zeta']);
        Contact::factory()->forCompany($this->company)->create(['priority' => 5, 'name' => 'Alpha']);

        $ordered = Contact::forCompany($this->company->id)->orderByPriority()->get();

        $this->assertEquals('Alpha', $ordered->first()->name);
        $this->assertEquals('Zeta', $ordered->last()->name);
    }

    public function test_entity_type_constants(): void
    {
        $this->assertEquals('vehicle', Contact::ENTITY_VEHICLE);
        $this->assertEquals('driver', Contact::ENTITY_DRIVER);
    }

    public function test_type_constants(): void
    {
        $this->assertEquals('monitoring_team', Contact::TYPE_MONITORING_TEAM);
        $this->assertEquals('supervisor', Contact::TYPE_SUPERVISOR);
        $this->assertEquals('emergency', Contact::TYPE_EMERGENCY);
        $this->assertEquals('dispatch', Contact::TYPE_DISPATCH);
    }
}
