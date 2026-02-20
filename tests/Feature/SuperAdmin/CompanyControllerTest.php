<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class CompanyControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin = $this->setUpSuperAdmin();
    }

    public function test_index_lists_companies(): void
    {
        Company::factory()->count(3)->create();

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/companies')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/companies/index')
                ->has('companies')
            );
    }

    public function test_create_shows_form(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/super-admin/companies/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/companies/create')
            );
    }

    public function test_store_creates_company_with_admin(): void
    {
        $this->actingAs($this->superAdmin)
            ->post('/super-admin/companies', [
                'name' => 'New Test Company',
                'email' => 'company@test.com',
                'admin_name' => 'Admin User',
                'admin_email' => 'admin@test.com',
                'admin_password' => 'Password123!',
                'admin_password_confirmation' => 'Password123!',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('companies', ['name' => 'New Test Company']);
        $this->assertDatabaseHas('users', ['email' => 'admin@test.com']);
    }

    public function test_edit_shows_company(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->superAdmin)
            ->get("/super-admin/companies/{$company->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/companies/edit')
            );
    }

    public function test_update_company(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->superAdmin)
            ->put("/super-admin/companies/{$company->id}", [
                'name' => 'Updated Company',
                'email' => $company->email,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Updated Company',
        ]);
    }

    public function test_toggle_status(): void
    {
        $company = Company::factory()->create(['is_active' => true]);

        $this->actingAs($this->superAdmin)
            ->post("/super-admin/companies/{$company->id}/toggle-status")
            ->assertRedirect();

        $company->refresh();
        $this->assertFalse($company->is_active);
    }

    public function test_update_samsara_key(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->superAdmin)
            ->put("/super-admin/companies/{$company->id}/samsara-key", [
                'samsara_api_key' => 'samsara_api_12345678901234567890',
            ])
            ->assertRedirect();

        $company->refresh();
        $this->assertNotNull($company->samsara_api_key);
    }

    public function test_remove_samsara_key(): void
    {
        $company = Company::factory()->withSamsaraApiKey()->create();

        $this->actingAs($this->superAdmin)
            ->delete("/super-admin/companies/{$company->id}/samsara-key")
            ->assertRedirect();

        $company->refresh();
        $this->assertNull($company->samsara_api_key);
    }

    public function test_destroy_company(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->superAdmin)
            ->delete("/super-admin/companies/{$company->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('companies', ['id' => $company->id]);
    }

    public function test_regular_user_cannot_access(): void
    {
        $this->setUpTenant();

        $this->actingAs($this->user)
            ->get('/super-admin/companies')
            ->assertForbidden();
    }
}
