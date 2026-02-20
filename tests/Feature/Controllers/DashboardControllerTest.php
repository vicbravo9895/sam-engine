<?php

namespace Tests\Feature\Controllers;

use App\Models\Alert;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_dashboard_returns_inertia_page(): void
    {
        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
            );
    }

    public function test_dashboard_includes_stats(): void
    {
        $this->createCompletedAlert($this->company);
        Vehicle::factory()->forCompany($this->company)->create();

        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->has('samsaraStats')
                ->has('vehiclesStats')
            );
    }

    public function test_dashboard_scoped_to_company(): void
    {
        $this->createCompletedAlert($this->company);

        [$otherCompany] = $this->createOtherTenant();
        $this->createCompletedAlert($otherCompany);
        $this->createCompletedAlert($otherCompany);

        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('samsaraStats.total', 1)
            );
    }

    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $this->get('/dashboard')
            ->assertRedirect('/login');
    }
}
