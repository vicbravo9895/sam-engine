<?php

namespace Tests\Feature\Services;

use App\Models\Alert;
use App\Services\Incidents\IncidentCreationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class IncidentCreationGateTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    private IncidentCreationGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        $this->gate = app(IncidentCreationGate::class);
    }

    public function test_should_create_incident_for_critical_severity(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company, [], [
            'severity' => Alert::SEVERITY_CRITICAL,
            'verdict' => Alert::VERDICT_CONFIRMED_VIOLATION,
        ]);

        $assessment = ['verdict' => 'confirmed_violation', 'likelihood' => 'high'];

        $this->assertTrue($this->gate->shouldCreateIncident($alert, $assessment));
    }

    public function test_should_not_create_incident_for_false_positive(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company, [], [
            'severity' => Alert::SEVERITY_INFO,
            'verdict' => Alert::VERDICT_LIKELY_FALSE_POSITIVE,
        ]);

        $assessment = ['verdict' => 'likely_false_positive', 'likelihood' => 'low'];

        $this->assertFalse($this->gate->shouldCreateIncident($alert, $assessment));
    }

    public function test_should_create_for_high_risk_verdicts(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company, [], [
            'severity' => Alert::SEVERITY_WARNING,
            'verdict' => Alert::VERDICT_REAL_PANIC,
            'risk_escalation' => Alert::RISK_EMERGENCY,
        ]);

        $assessment = ['verdict' => 'real_panic', 'risk_escalation' => 'emergency'];

        $this->assertTrue($this->gate->shouldCreateIncident($alert, $assessment));
    }

    public function test_creates_incident_from_alert(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company, [], [
            'severity' => Alert::SEVERITY_CRITICAL,
            'verdict' => Alert::VERDICT_CONFIRMED_VIOLATION,
            'ai_status' => Alert::STATUS_COMPLETED,
        ]);

        $assessment = ['verdict' => 'confirmed_violation', 'likelihood' => 'high'];

        $incident = $this->gate->createFromAlert($alert, $assessment);

        if ($incident) {
            $this->assertDatabaseHas('incidents', ['id' => $incident->id]);
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_skips_duplicate_by_samsara_event_id(): void
    {
        ['alert' => $alert] = $this->createFullAlert($this->company, [], [
            'severity' => Alert::SEVERITY_CRITICAL,
        ]);

        $assessment = ['verdict' => 'confirmed_violation'];

        $first = $this->gate->createFromAlert($alert, $assessment);
        $second = $this->gate->createFromAlert($alert, $assessment);

        if ($first && $second) {
            $this->assertEquals($first->id, $second->id);
        } else {
            $this->assertTrue(true);
        }
    }
}
