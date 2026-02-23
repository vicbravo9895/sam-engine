<?php

namespace Tests\Feature\Controllers;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class IncidentControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_index_returns_inertia_page(): void
    {
        Incident::factory()->forCompany($this->company)->create();

        $this->actingAs($this->user)
            ->get('/incidents')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('incidents/index')
                ->has('incidents')
                ->has('stats')
                ->has('priorityCounts')
                ->has('filters')
            );
    }

    public function test_index_returns_error_for_user_without_company(): void
    {
        $userWithoutCompany = User::factory()->create(['company_id' => null]);

        $this->actingAs($userWithoutCompany)
            ->get('/incidents')
            ->assertStatus(500);
    }

    public function test_index_filters_by_status(): void
    {
        Incident::factory()->forCompany($this->company)->open()->create();
        Incident::factory()->forCompany($this->company)->resolved()->create();

        $this->actingAs($this->user)
            ->get('/incidents?status=open')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('incidents/index')
                ->where('filters.status', 'open')
                ->has('incidents.data', 1)
                ->where('incidents.data.0.status', 'open')
            );
    }

    public function test_index_filters_by_priority(): void
    {
        Incident::factory()->forCompany($this->company)->p1()->create();
        Incident::factory()->forCompany($this->company)->p2()->create();
        Incident::factory()->forCompany($this->company)->p3()->create();

        $this->actingAs($this->user)
            ->get('/incidents?priority=P1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('incidents/index')
                ->where('filters.priority', 'P1')
                ->has('incidents.data', 1)
                ->where('incidents.data.0.priority', 'P1')
            );
    }

    public function test_index_scoped_to_company(): void
    {
        Incident::factory()->forCompany($this->company)->create();

        [$otherCompany] = $this->createOtherTenant();
        Incident::factory()->forCompany($otherCompany)->create();
        Incident::factory()->forCompany($otherCompany)->create();

        $this->actingAs($this->user)
            ->get('/incidents')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('incidents/index')
                ->has('incidents.data', 1)
                ->where('stats.total', 1)
            );
    }

    public function test_show_returns_incident_detail(): void
    {
        $incident = Incident::factory()->forCompany($this->company)->create();

        $this->actingAs($this->user)
            ->get("/incidents/{$incident->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('incidents/show')
                ->has('incident')
                ->where('incident.id', $incident->id)
                ->where('incident.status', $incident->status)
                ->has('incident.safety_signals')
            );
    }

    public function test_show_returns_403_for_other_company_incident(): void
    {
        [$otherCompany] = $this->createOtherTenant();
        $otherIncident = Incident::factory()->forCompany($otherCompany)->create();

        $this->actingAs($this->user)
            ->get("/incidents/{$otherIncident->id}")
            ->assertForbidden();
    }

    public function test_update_status_to_resolved(): void
    {
        $incident = Incident::factory()->forCompany($this->company)->open()->create();

        $this->actingAs($this->user)
            ->patch("/incidents/{$incident->id}/status", ['status' => 'resolved'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $incident->refresh();
        $this->assertSame(Incident::STATUS_RESOLVED, $incident->status);
        $this->assertNotNull($incident->resolved_at);
    }

    public function test_update_status_to_false_positive(): void
    {
        $incident = Incident::factory()->forCompany($this->company)->open()->create();

        $this->actingAs($this->user)
            ->patch("/incidents/{$incident->id}/status", ['status' => 'false_positive'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $incident->refresh();
        $this->assertSame(Incident::STATUS_FALSE_POSITIVE, $incident->status);
        $this->assertNotNull($incident->resolved_at);
    }

    public function test_update_status_to_investigating(): void
    {
        $incident = Incident::factory()->forCompany($this->company)->open()->create();

        $this->actingAs($this->user)
            ->patch("/incidents/{$incident->id}/status", ['status' => 'investigating'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $incident->refresh();
        $this->assertSame(Incident::STATUS_INVESTIGATING, $incident->status);
        $this->assertNull($incident->resolved_at);
    }

    public function test_update_status_returns_403_for_other_company(): void
    {
        [$otherCompany] = $this->createOtherTenant();
        $otherIncident = Incident::factory()->forCompany($otherCompany)->create();

        $this->actingAs($this->user)
            ->patch("/incidents/{$otherIncident->id}/status", ['status' => 'resolved'])
            ->assertForbidden();
    }

    public function test_update_status_validates_input(): void
    {
        $incident = Incident::factory()->forCompany($this->company)->create();

        $this->actingAs($this->user)
            ->patch("/incidents/{$incident->id}/status", ['status' => 'invalid_status'])
            ->assertSessionHasErrors(['status']);
    }

    public function test_unauthenticated_user_redirects_to_login(): void
    {
        $this->get('/incidents')
            ->assertRedirect('/login');

        $incident = Incident::factory()->forCompany($this->company)->create();

        $this->get("/incidents/{$incident->id}")
            ->assertRedirect('/login');

        $this->patch("/incidents/{$incident->id}/status", ['status' => 'resolved'])
            ->assertRedirect('/login');
    }
}
