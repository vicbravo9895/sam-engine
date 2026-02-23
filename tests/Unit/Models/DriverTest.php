<?php

namespace Tests\Unit\Models;

use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class DriverTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_company_relationship(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create();

        $this->assertNotNull($driver->company);
        $this->assertEquals($this->company->id, $driver->company->id);
    }

    public function test_assigned_vehicle_relationship(): void
    {
        $vehicle = Vehicle::factory()->forCompany($this->company)->create(['samsara_id' => 'vehicle-123']);
        $driver = Driver::factory()->forCompany($this->company)->create([
            'samsara_id' => 'driver-123',
            'assigned_vehicle_samsara_id' => 'vehicle-123',
        ]);

        $this->assertNotNull($driver->assignedVehicle);
        $this->assertEquals($vehicle->id, $driver->assignedVehicle->id);
    }

    public function test_assigned_vehicle_returns_null_when_no_vehicle_assigned(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'assigned_vehicle_samsara_id' => null,
        ]);

        $this->assertNull($driver->assignedVehicle);
    }

    public function test_scope_for_company_filters_by_company(): void
    {
        Driver::factory()->forCompany($this->company)->create();
        [$otherCompany] = $this->createOtherTenant();
        Driver::factory()->forCompany($otherCompany)->create();

        $result = Driver::forCompany($this->company->id)->get();
        $this->assertCount(1, $result);
        $this->assertEquals($this->company->id, $result->first()->company_id);
    }

    public function test_scope_active_filters_non_deactivated_and_active_status(): void
    {
        Driver::factory()->forCompany($this->company)->create([
            'is_deactivated' => false,
            'driver_activation_status' => 'active',
        ]);
        Driver::factory()->forCompany($this->company)->deactivated()->create();
        Driver::factory()->forCompany($this->company)->create([
            'is_deactivated' => false,
            'driver_activation_status' => 'inactive',
        ]);

        $result = Driver::forCompany($this->company->id)->active()->get();
        $this->assertCount(1, $result);
        $this->assertFalse($result->first()->is_deactivated);
        $this->assertEquals('active', $result->first()->driver_activation_status);
    }

    public function test_generate_data_hash_returns_consistent_hash(): void
    {
        $data = ['id' => 'd1', 'name' => 'John Doe', 'phone' => '+521234567890'];
        $hash1 = Driver::generateDataHash($data);
        $hash2 = Driver::generateDataHash($data);

        $this->assertSame($hash1, $hash2);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash1);
    }

    public function test_has_data_changed_returns_true_when_hash_differs(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'data_hash' => Driver::generateDataHash(['id' => 'd1', 'name' => 'John']),
        ]);

        $newData = ['id' => 'd1', 'name' => 'Jane'];
        $this->assertTrue($driver->hasDataChanged($newData));
    }

    public function test_has_data_changed_returns_false_when_hash_matches(): void
    {
        $data = ['id' => 'd1', 'name' => 'John Doe'];
        $driver = Driver::factory()->forCompany($this->company)->create([
            'data_hash' => Driver::generateDataHash($data),
        ]);

        $this->assertFalse($driver->hasDataChanged($data));
    }

    public function test_sync_from_samsara_creates_new_driver(): void
    {
        $samsaraData = [
            'id' => 'samsara-driver-' . uniqid(),
            'name' => 'John Doe',
            'username' => 'johndoe',
            'phone' => '+521234567890',
            'driverActivationStatus' => 'active',
            'isDeactivated' => false,
        ];

        $driver = Driver::syncFromSamsara($samsaraData, $this->company->id);

        $this->assertInstanceOf(Driver::class, $driver);
        $this->assertEquals($this->company->id, $driver->company_id);
        $this->assertEquals($samsaraData['id'], $driver->samsara_id);
        $this->assertEquals('John Doe', $driver->name);
        $this->assertEquals('johndoe', $driver->username);
        $this->assertEquals('active', $driver->driver_activation_status);
        $this->assertFalse($driver->is_deactivated);
    }

    public function test_sync_from_samsara_updates_existing_driver_when_data_changed(): void
    {
        $samsaraId = 'samsara-driver-' . uniqid();
        $driver = Driver::factory()->forCompany($this->company)->create([
            'samsara_id' => $samsaraId,
            'name' => 'Old Name',
            'data_hash' => Driver::generateDataHash(['id' => $samsaraId, 'name' => 'Old Name']),
        ]);

        $samsaraData = [
            'id' => $samsaraId,
            'name' => 'New Name',
            'phone' => '+521234567890',
        ];

        $updated = Driver::syncFromSamsara($samsaraData, $this->company->id);

        $this->assertEquals($driver->id, $updated->id);
        $this->assertEquals('New Name', $updated->name);
    }

    public function test_sync_from_samsara_skips_update_when_hash_unchanged(): void
    {
        $samsaraId = 'samsara-driver-' . uniqid();
        $samsaraData = [
            'id' => $samsaraId,
            'name' => 'John Doe',
            'phone' => '+521234567890',
        ];
        $driver = Driver::factory()->forCompany($this->company)->create([
            'samsara_id' => $samsaraId,
            'name' => 'John Doe',
            'data_hash' => Driver::generateDataHash($samsaraData),
        ]);

        $result = Driver::syncFromSamsara($samsaraData, $this->company->id);

        $this->assertEquals($driver->id, $result->id);
        $this->assertEquals('John Doe', $result->name);
    }

    public function test_display_name_includes_license_when_available(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'name' => 'John Doe',
            'license_number' => 'LIC123',
            'license_state' => 'CDMX',
        ]);

        $this->assertSame('John Doe (CDMX: LIC123)', $driver->display_name);
    }

    public function test_display_name_excludes_license_when_missing(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'name' => 'John Doe',
            'license_number' => null,
            'license_state' => null,
        ]);

        $this->assertSame('John Doe', $driver->display_name);
    }

    public function test_carrier_name_returns_from_carrier_settings(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'carrier_settings' => ['carrierName' => 'Acme Transport'],
        ]);

        $this->assertSame('Acme Transport', $driver->carrier_name);
    }

    public function test_carrier_name_returns_null_when_not_set(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'carrier_settings' => null,
        ]);

        $this->assertNull($driver->carrier_name);
    }

    public function test_assigned_vehicle_name_returns_from_static_assigned_vehicle(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'static_assigned_vehicle' => ['id' => 'v1', 'name' => 'T-001'],
        ]);

        $this->assertSame('T-001', $driver->assigned_vehicle_name);
    }

    public function test_assigned_vehicle_id_returns_from_static_assigned_vehicle(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'static_assigned_vehicle' => ['id' => 'v1', 'name' => 'T-001'],
        ]);

        $this->assertSame('v1', $driver->assigned_vehicle_id);
    }

    public function test_formatted_phone_returns_null_when_phone_empty(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'phone' => null,
            'country_code' => '52',
        ]);

        $this->assertNull($driver->formatted_phone);
    }

    public function test_formatted_phone_returns_null_when_country_code_empty(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'phone' => '2721305381',
            'country_code' => null,
        ]);

        $this->assertNull($driver->formatted_phone);
    }

    public function test_formatted_phone_returns_formatted_number(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'phone' => '2721305381',
            'country_code' => '52',
        ]);

        $this->assertSame('+522721305381', $driver->formatted_phone);
    }

    public function test_formatted_phone_extracts_last_10_digits_from_long_number(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'phone' => '5212721305381',
            'country_code' => '52',
        ]);

        $this->assertSame('+522721305381', $driver->formatted_phone);
    }

    public function test_formatted_whatsapp_adds_mobile_prefix_for_mexico(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'phone' => '2721305381',
            'country_code' => '52',
        ]);

        $this->assertSame('+5212721305381', $driver->formatted_whatsapp);
    }

    public function test_formatted_whatsapp_without_mobile_prefix_for_non_mexico(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'phone' => '5551234567',
            'country_code' => '1',
        ]);

        $this->assertSame('+15551234567', $driver->formatted_whatsapp);
    }

    public function test_formatted_whatsapp_returns_null_when_phone_empty(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'phone' => null,
            'country_code' => '52',
        ]);

        $this->assertNull($driver->formatted_whatsapp);
    }

    public function test_formatted_whatsapp_returns_null_when_phone_too_short(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'phone' => '123',
            'country_code' => '52',
        ]);

        $this->assertNull($driver->formatted_whatsapp);
    }

    public function test_get_available_country_codes_returns_array(): void
    {
        $codes = Driver::getAvailableCountryCodes();

        $this->assertIsArray($codes);
        $this->assertArrayHasKey('52', $codes);
        $this->assertStringContainsString('México', $codes['52']);
        $this->assertArrayHasKey('1', $codes);
    }

    public function test_map_samsara_data_maps_camel_case_to_snake_case(): void
    {
        $samsaraData = [
            'id' => 'd1',
            'name' => 'John Doe',
            'profileImageUrl' => 'https://example.com/photo.jpg',
            'driverActivationStatus' => 'active',
            'isDeactivated' => false,
            'staticAssignedVehicle' => ['id' => 'v1', 'name' => 'T-001'],
            'createdAtTime' => '2024-01-15T10:00:00Z',
            'updatedAtTime' => '2024-01-15T12:00:00Z',
        ];

        $driver = Driver::syncFromSamsara($samsaraData, $this->company->id);

        $this->assertEquals('https://example.com/photo.jpg', $driver->profile_image_url);
        $this->assertEquals('active', $driver->driver_activation_status);
        $this->assertFalse($driver->is_deactivated);
        $this->assertEquals('v1', $driver->assigned_vehicle_samsara_id);
        $this->assertNotNull($driver->samsara_created_at);
        $this->assertNotNull($driver->samsara_updated_at);
    }
}
