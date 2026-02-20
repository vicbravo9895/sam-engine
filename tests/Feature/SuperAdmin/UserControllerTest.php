<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class UserControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin = $this->setUpSuperAdmin();
    }

    public function test_index_lists_all_users(): void
    {
        $company = Company::factory()->create();
        User::factory()->forCompany($company)->count(3)->create();

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/users/index')
                ->has('users')
            );
    }

    public function test_create_shows_form(): void
    {
        Company::factory()->create();

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/users/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/users/create')
                ->has('companies')
            );
    }

    public function test_store_creates_user(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/users', [
                'name' => 'New Admin User',
                'email' => 'newadmin@test.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'company_id' => $company->id,
                'role' => 'admin',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'newadmin@test.com',
            'company_id' => $company->id,
        ]);
    }

    public function test_toggle_user_status(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->forCompany($company)->create(['is_active' => true]);

        $this->actingAs($this->superAdmin)
            ->post("/super-admin/users/{$user->id}/toggle-status")
            ->assertRedirect();

        $user->refresh();
        $this->assertFalse($user->is_active);
    }

    public function test_destroy_user(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->forCompany($company)->create();

        $this->actingAs($this->superAdmin)
            ->delete("/super-admin/users/{$user->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_regular_user_cannot_access(): void
    {
        $this->setUpTenant();

        $this->actingAs($this->user)
            ->get('/super-admin/users')
            ->assertForbidden();
    }
}
