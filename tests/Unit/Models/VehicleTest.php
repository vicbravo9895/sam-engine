<?php

namespace Tests\Unit\Models;

use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class VehicleTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_company_relationship(): void
    {
        $vehicle = Vehicle::factory()->forCompany($this->company)->create();

        $this->assertNotNull($vehicle->company);
        $this->assertEquals($this->company->id, $vehicle->company->id);
    }

    public function test_assigned_driver_relationship(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create(['samsara_id' => 'driver-123']);
        $vehicle = Vehicle::factory()->forCompany($this->company)->create([
            'samsara_id' => 'vehicle-123',
            'assigned_driver_samsara_id' => 'driver-123',
        ]);

        $this->assertNotNull($vehicle->assignedDriver);
        $this->assertEquals($driver->id, $vehicle->assignedDriver->id);
    }

    public function test_assigned_driver_returns_null_when_no_driver_assigned(): void
    {
        $vehicle = Vehicle::factory()->forCompany($this->company)->create([
            'assigned_driver_samsara_id' => null,
        ]);

        $this->assertNull($vehicle->assignedDriver);
    }

    public function test_scope_for_company_filters_by_company(): void
    {
        Vehicle::factory()->forCompany($this->company)->create();
        [$otherCompany] = $this->createOtherTenant();
        Vehicle::factory()->forCompany($otherCompany)->create();

        $result = Vehicle::forCompany($this->company->id)->get();
        $this->assertCount(1, $result);
        $this->assertEquals($this->company->id, $result->first()->company_id);
    }

    public function test_generate_data_hash_returns_consistent_hash(): void
    {
        $data = ['id' => 'v1', 'name' => 'T-001', 'vin' => 'ABC123'];
        $hash1 = Vehicle::generateDataHash($data);
        $hash2 = Vehicle::generateDataHash($data);

        $this->assertSame($hash1, $hash2);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash1);
    }

    public function test_generate_data_hash_different_for_different_data(): void
    {
        $hash1 = Vehicle::generateDataHash(['id' => 'v1', 'name' => 'T-001']);
        $hash2 = Vehicle::generateDataHash(['id' => 'v1', 'name' => 'T-002']);

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_has_data_changed_returns_true_when_hash_differs(): void
    {
        $vehicle = Vehicle::factory()->forCompany($this->company)->create([
            'data_hash' => Vehicle::generateDataHash(['id' => 'v1', 'name' => 'T-001']),
        ]);

        $newData = ['id' => 'v1', 'name' => 'T-002'];
        $this->assertTrue($vehicle->hasDataChanged($newData));
    }

    public function test_has_data_changed_returns_false_when_hash_matches(): void
    {
        $data = ['id' => 'v1', 'name' => 'T-001'];
        $vehicle = Vehicle::factory()->forCompany($this->company)->create([
            'data_hash' => Vehicle::generateDataHash($data),
        ]);

        $this->assertFalse($vehicle->hasDataChanged($data));
    }

    public function test_sync_from_samsara_creates_new_vehicle(): void
    {
        $samsaraData = [
            'id' => 'samsara-vehicle-' . uniqid(),
            'name' => 'T-012345',
            'vin' => '1HGBH41JXMN109186',
            'licensePlate' => 'ABC-12-34',
            'make' => 'Freightliner',
            'model' => 'Cascadia',
            'year' => 2023,
        ];

        $vehicle = Vehicle::syncFromSamsara($samsaraData, $this->company->id);

        $this->assertInstanceOf(Vehicle::class, $vehicle);
        $this->assertEquals($this->company->id, $vehicle->company_id);
        $this->assertEquals($samsaraData['id'], $vehicle->samsara_id);
        $this->assertEquals('T-012345', $vehicle->name);
        $this->assertEquals('1HGBH41JXMN109186', $vehicle->vin);
        $this->assertEquals('ABC-12-34', $vehicle->license_plate);
        $this->assertEquals('Freightliner', $vehicle->make);
        $this->assertEquals('Cascadia', $vehicle->model);
        $this->assertEquals(2023, $vehicle->year);
    }

    public function test_sync_from_samsara_updates_existing_vehicle_when_data_changed(): void
    {
        $samsaraId = 'samsara-vehicle-' . uniqid();
        $vehicle = Vehicle::factory()->forCompany($this->company)->create([
            'samsara_id' => $samsaraId,
            'name' => 'T-OLD',
            'data_hash' => Vehicle::generateDataHash(['id' => $samsaraId, 'name' => 'T-OLD']),
        ]);

        $samsaraData = [
            'id' => $samsaraId,
            'name' => 'T-NEW',
            'vin' => '1HGBH41JXMN109186',
        ];

        $updated = Vehicle::syncFromSamsara($samsaraData, $this->company->id);

        $this->assertEquals($vehicle->id, $updated->id);
        $this->assertEquals('T-NEW', $updated->name);
    }

    public function test_sync_from_samsara_skips_update_when_hash_unchanged(): void
    {
        $samsaraId = 'samsara-vehicle-' . uniqid();
        $samsaraData = [
            'id' => $samsaraId,
            'name' => 'T-001',
            'vin' => '1HGBH41JXMN109186',
        ];
        $vehicle = Vehicle::factory()->forCompany($this->company)->create([
            'samsara_id' => $samsaraId,
            'name' => 'T-001',
            'data_hash' => Vehicle::generateDataHash($samsaraData),
        ]);

        $result = Vehicle::syncFromSamsara($samsaraData, $this->company->id);

        $this->assertEquals($vehicle->id, $result->id);
        $this->assertEquals('T-001', $result->name);
    }

    public function test_sync_from_samsara_without_company_id(): void
    {
        $samsaraData = [
            'id' => 'samsara-vehicle-' . uniqid(),
            'name' => 'T-001',
        ];

        $vehicle = Vehicle::syncFromSamsara($samsaraData, null);

        $this->assertInstanceOf(Vehicle::class, $vehicle);
        $this->assertEquals($samsaraData['id'], $vehicle->samsara_id);
        $this->assertNull($vehicle->company_id);
    }

    public function test_map_samsara_data_maps_camel_case_to_snake_case(): void
    {
        $samsaraData = [
            'id' => 'v1',
            'name' => 'T-001',
            'licensePlate' => 'ABC-12',
            'vehicleType' => 'truck',
            'isRemotePrivacyButtonEnabled' => true,
            'createdAtTime' => '2024-01-15T10:00:00Z',
            'updatedAtTime' => '2024-01-15T12:00:00Z',
        ];

        $vehicle = Vehicle::syncFromSamsara($samsaraData, $this->company->id);

        $this->assertEquals('ABC-12', $vehicle->license_plate);
        $this->assertEquals('truck', $vehicle->vehicle_type);
        $this->assertTrue($vehicle->is_remote_privacy_button_enabled);
        $this->assertNotNull($vehicle->samsara_created_at);
        $this->assertNotNull($vehicle->samsara_updated_at);
    }
}
