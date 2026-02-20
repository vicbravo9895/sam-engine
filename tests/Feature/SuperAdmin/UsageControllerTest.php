<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\UsageDailySummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class UsageControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin = $this->setUpSuperAdmin();
    }

    public function test_index_shows_usage_dashboard(): void
    {
        Company::factory()->count(2)->create();

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/usage')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/usage/index')
            );
    }

    public function test_show_company_usage(): void
    {
        $company = Company::factory()->create();
        UsageDailySummary::factory()->create(['company_id' => $company->id]);

        $this->actingAs($this->superAdmin)
            ->get("/super-admin/usage/{$company->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/usage/show')
            );
    }

    public function test_export_returns_csv(): void
    {
        $company = Company::factory()->create();
        UsageDailySummary::factory()->create(['company_id' => $company->id]);

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/usage/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_regular_user_cannot_access(): void
    {
        $this->setUpTenant();

        $this->actingAs($this->user)
            ->get('/super-admin/usage')
            ->assertForbidden();
    }
}
