<?php

namespace Tests\Feature\Services;

use App\Models\Alert;
use App\Models\Incident;
use App\Services\Incidents\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class IncidentServiceTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    private IncidentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        $this->service = app(IncidentService::class);
    }

    public function test_creates_incident_from_alert(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company, [], [
            'severity' => Alert::SEVERITY_CRITICAL,
            'ai_status' => Alert::STATUS_COMPLETED,
        ]);

        $incident = $this->service->createFromAlert($alert);

        $this->assertInstanceOf(Incident::class, $incident);
        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_resolves_incident(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company, [], [
            'severity' => Alert::SEVERITY_CRITICAL,
        ]);

        $incident = $this->service->createFromAlert($alert);
        $this->service->resolve($incident, 'Verified and resolved.');

        $incident->refresh();
        $this->assertEquals('resolved', $incident->status);
    }

    public function test_marks_as_false_positive(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company, [], [
            'severity' => Alert::SEVERITY_WARNING,
        ]);

        $incident = $this->service->createFromAlert($alert);
        $this->service->markAsFalsePositive($incident, 'Not a real incident.');

        $incident->refresh();
        $this->assertEquals('false_positive', $incident->status);
    }

    public function test_determines_priority(): void
    {
        ['alert' => $alert] = $this->createCriticalPanicAlert($this->company);
        $incident = $this->service->createFromAlert($alert);

        $this->assertNotNull($incident->priority);
    }
}
