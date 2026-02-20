<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class DashboardTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    public function test_super_admin_can_access_dashboard(): void
    {
        $superAdmin = $this->setUpSuperAdmin();
        $company = Company::factory()->withSamsaraApiKey()->create();
        $this->createCompletedAlert($company);

        $this->actingAs($superAdmin)
            ->get('/super-admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/dashboard')
            );
    }

    public function test_regular_user_cannot_access_super_admin(): void
    {
        $this->setUpTenant();

        $this->actingAs($this->user)
            ->get('/super-admin')
            ->assertForbidden();
    }

    public function test_dashboard_includes_global_stats(): void
    {
        $superAdmin = $this->setUpSuperAdmin();
        Company::factory()->count(3)->create();

        $this->actingAs($superAdmin)
            ->get('/super-admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/dashboard')
                ->has('stats')
            );
    }
}
