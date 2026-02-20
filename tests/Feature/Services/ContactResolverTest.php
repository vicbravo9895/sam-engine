<?php

namespace Tests\Feature\Services;

use App\Models\Contact;
use App\Models\Driver;
use App\Services\ContactResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class ContactResolverTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    private ContactResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        $this->resolver = app(ContactResolver::class);
    }

    public function test_resolves_operator_from_driver(): void
    {
        $driver = Driver::factory()->forCompany($this->company)->create([
            'samsara_id' => 'driver-123',
            'name' => 'Carlos Martinez',
            'phone' => '+5218117658890',
            'country_code' => '52',
        ]);

        $contacts = $this->resolver->resolve(null, 'driver-123', $this->company->id);

        $this->assertArrayHasKey('operator', $contacts);
        $this->assertEquals('Carlos Martinez', $contacts['operator']['name']);
    }

    public function test_resolves_monitoring_team_contact(): void
    {
        Contact::factory()->monitoringTeam()->forCompany($this->company)->default()->create([
            'name' => 'Central Monitor',
        ]);

        $contacts = $this->resolver->resolve(null, null, $this->company->id);

        $this->assertArrayHasKey('monitoring_team', $contacts);
    }

    public function test_resolves_vehicle_specific_contact(): void
    {
        Contact::factory()->monitoringTeam()->forCompany($this->company)->create([
            'entity_type' => Contact::ENTITY_VEHICLE,
            'entity_id' => 'vehicle-456',
            'name' => 'Vehicle-specific Monitor',
        ]);

        Contact::factory()->monitoringTeam()->forCompany($this->company)->default()->create([
            'name' => 'Global Monitor',
        ]);

        $contacts = $this->resolver->resolve('vehicle-456', null, $this->company->id);

        $this->assertArrayHasKey('monitoring_team', $contacts);
        $this->assertStringContainsString('Vehicle-specific', $contacts['monitoring_team']['name']);
    }

    public function test_falls_back_to_global_default(): void
    {
        Contact::factory()->monitoringTeam()->forCompany($this->company)->default()->create([
            'name' => 'Global Default',
        ]);

        $contacts = $this->resolver->resolve('nonexistent-vehicle', null, $this->company->id);

        $this->assertArrayHasKey('monitoring_team', $contacts);
    }

    public function test_formats_contacts_for_payload(): void
    {
        Contact::factory()->monitoringTeam()->forCompany($this->company)->default()->create([
            'phone' => '+5218117658890',
        ]);

        $contacts = $this->resolver->resolve(null, null, $this->company->id);
        $payload = $this->resolver->formatForPayload($contacts);

        $this->assertArrayHasKey('monitoring_team_number', $payload);
        $this->assertArrayHasKey('notification_contacts', $payload);
    }

    public function test_handles_missing_driver(): void
    {
        $contacts = $this->resolver->resolve(null, 'nonexistent-driver', $this->company->id);

        $this->assertArrayNotHasKey('operator', $contacts);
    }

    public function test_resolves_for_alert(): void
    {
        Driver::factory()->forCompany($this->company)->create([
            'samsara_id' => 'driver-789',
        ]);

        ['alert' => $alert] = $this->createFullAlert($this->company, [
            'driver_id' => 'driver-789',
        ]);

        $contacts = $this->resolver->resolveForAlert($alert);

        $this->assertIsArray($contacts);
    }
}
