<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\DomainEvent;
use App\Models\UsageDailySummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class SuperAdminControllersTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    private User $superAdmin;
    private Company $companyA;
    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->setUpSuperAdmin();
        $this->companyA = Company::factory()->withSamsaraApiKey()->create(['name' => 'Acme Corp']);
        $this->companyB = Company::factory()->withSamsaraApiKey()->create(['name' => 'Beta Inc']);
    }

    // ── UserController: index ───────────────────────────────────────

    public function test_index_lists_users_for_super_admin(): void
    {
        User::factory()->forCompany($this->companyA)->count(3)->create();
        User::factory()->forCompany($this->companyB)->count(2)->create();

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/users/index')
                ->has('users.data', 5)
                ->has('companies')
                ->has('roles')
                ->has('filters')
            );
    }

    public function test_index_filters_by_search(): void
    {
        User::factory()->forCompany($this->companyA)->create(['name' => 'Carlos Pérez', 'email' => 'carlos@acme.com']);
        User::factory()->forCompany($this->companyA)->create(['name' => 'María López', 'email' => 'maria@acme.com']);

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/users?search=Carlos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/users/index')
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Carlos Pérez')
            );
    }

    public function test_index_filters_by_company(): void
    {
        User::factory()->forCompany($this->companyA)->count(2)->create();
        User::factory()->forCompany($this->companyB)->count(3)->create();

        $this->actingAs($this->superAdmin)
            ->get("/super-admin/users?company_id={$this->companyB->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/users/index')
                ->has('users.data', 3)
            );
    }

    public function test_index_filters_by_role(): void
    {
        User::factory()->admin()->forCompany($this->companyA)->create();
        User::factory()->manager()->forCompany($this->companyA)->create();
        User::factory()->forCompany($this->companyA)->count(2)->create();

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/users?role=admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/users/index')
                ->has('users.data', 1)
                ->where('users.data.0.role', 'admin')
            );
    }

    public function test_index_filters_by_active_status(): void
    {
        User::factory()->forCompany($this->companyA)->create(['is_active' => true]);
        User::factory()->forCompany($this->companyA)->create(['is_active' => true]);
        User::factory()->forCompany($this->companyA)->create(['is_active' => false]);

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/users?is_active=0')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/users/index')
                ->has('users.data', 1)
                ->where('users.data.0.is_active', false)
            );
    }

    public function test_index_forbidden_for_regular_user(): void
    {
        $this->setUpTenant();

        $this->actingAs($this->user)
            ->get('/super-admin/users')
            ->assertForbidden();
    }

    // ── UserController: create ──────────────────────────────────────

    public function test_create_shows_form(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/super-admin/users/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/users/create')
                ->has('companies')
                ->has('roles')
                ->where('selectedCompanyId', null)
            );
    }

    // ── UserController: store ───────────────────────────────────────

    public function test_store_creates_user(): void
    {
        $this->actingAs($this->superAdmin)
            ->post('/super-admin/users', [
                'company_id' => $this->companyA->id,
                'name' => 'New User',
                'email' => 'newuser@test.com',
                'password' => 'Password123!',
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ])
            ->assertRedirect(route('super-admin.users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@test.com',
            'company_id' => $this->companyA->id,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->superAdmin)
            ->from('/super-admin/users/create')
            ->post('/super-admin/users', [])
            ->assertSessionHasErrors(['company_id', 'name', 'email', 'password', 'role']);
    }

    // ── UserController: edit ────────────────────────────────────────

    public function test_edit_shows_user(): void
    {
        $user = User::factory()->admin()->forCompany($this->companyA)->create();

        $this->actingAs($this->superAdmin)
            ->get("/super-admin/users/{$user->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/users/edit')
                ->where('user.id', $user->id)
                ->where('user.email', $user->email)
                ->has('companies')
                ->has('roles')
            );
    }

    public function test_edit_forbidden_for_super_admin_user(): void
    {
        $targetSuperAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($this->superAdmin)
            ->get("/super-admin/users/{$targetSuperAdmin->id}/edit")
            ->assertForbidden();
    }

    // ── UserController: update ──────────────────────────────────────

    public function test_update_updates_user(): void
    {
        $user = User::factory()->forCompany($this->companyA)->create([
            'name' => 'Old Name',
            'role' => User::ROLE_USER,
        ]);

        $this->actingAs($this->superAdmin)
            ->put("/super-admin/users/{$user->id}", [
                'company_id' => $this->companyA->id,
                'name' => 'Updated Name',
                'email' => $user->email,
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_update_changes_password_when_provided(): void
    {
        $user = User::factory()->forCompany($this->companyA)->create();
        $oldPasswordHash = $user->password;

        $this->actingAs($this->superAdmin)
            ->put("/super-admin/users/{$user->id}", [
                'company_id' => $this->companyA->id,
                'name' => $user->name,
                'email' => $user->email,
                'password' => 'NewPassword123!',
                'role' => $user->role,
                'is_active' => true,
            ])
            ->assertRedirect();

        $user->refresh();
        $this->assertNotEquals($oldPasswordHash, $user->password);
    }

    public function test_update_emits_role_changed_event(): void
    {
        $user = User::factory()->forCompany($this->companyA)->create([
            'role' => User::ROLE_USER,
        ]);

        \Laravel\Pennant\Feature::define('ledger-v1', fn () => true);

        $this->actingAs($this->superAdmin)
            ->put("/super-admin/users/{$user->id}", [
                'company_id' => $this->companyA->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => User::ROLE_ADMIN,
        ]);
    }

    public function test_update_forbidden_for_super_admin_user(): void
    {
        $targetSuperAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($this->superAdmin)
            ->put("/super-admin/users/{$targetSuperAdmin->id}", [
                'company_id' => $this->companyA->id,
                'name' => 'Hacked',
                'email' => 'hacked@test.com',
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])
            ->assertForbidden();
    }

    // ── UserController: toggleStatus ────────────────────────────────

    public function test_toggle_status_toggles_active(): void
    {
        $user = User::factory()->forCompany($this->companyA)->create(['is_active' => true]);

        $this->actingAs($this->superAdmin)
            ->post("/super-admin/users/{$user->id}/toggle-status")
            ->assertRedirect();

        $user->refresh();
        $this->assertFalse($user->is_active);

        $this->actingAs($this->superAdmin)
            ->post("/super-admin/users/{$user->id}/toggle-status")
            ->assertRedirect();

        $user->refresh();
        $this->assertTrue($user->is_active);
    }

    public function test_toggle_status_forbidden_for_super_admin_user(): void
    {
        $targetSuperAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($this->superAdmin)
            ->post("/super-admin/users/{$targetSuperAdmin->id}/toggle-status")
            ->assertForbidden();
    }

    // ── UserController: destroy ─────────────────────────────────────

    public function test_destroy_deletes_user(): void
    {
        $user = User::factory()->forCompany($this->companyA)->create();

        $this->actingAs($this->superAdmin)
            ->delete("/super-admin/users/{$user->id}")
            ->assertRedirect(route('super-admin.users.index'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_destroy_forbidden_for_super_admin_user(): void
    {
        $targetSuperAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($this->superAdmin)
            ->delete("/super-admin/users/{$targetSuperAdmin->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $targetSuperAdmin->id]);
    }

    // ── UsageController: index ──────────────────────────────────────

    public function test_usage_index_returns_summaries(): void
    {
        UsageDailySummary::factory()->create([
            'company_id' => $this->companyA->id,
            'date' => now()->format('Y-m-d'),
            'meter' => 'alerts_processed',
            'total_qty' => 42,
        ]);

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/usage')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/usage/index')
                ->has('usage')
                ->has('dailySummaries')
                ->has('period')
                ->has('from')
                ->has('to')
            );
    }

    public function test_usage_index_with_last30_period(): void
    {
        UsageDailySummary::factory()->create([
            'company_id' => $this->companyA->id,
            'date' => now()->subDays(10)->format('Y-m-d'),
            'meter' => 'alerts_processed',
            'total_qty' => 15,
        ]);

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/usage?period=last30')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/usage/index')
                ->where('period', 'last30')
            );
    }

    // ── UsageController: show ───────────────────────────────────────

    public function test_usage_show_returns_company_usage(): void
    {
        UsageDailySummary::factory()->create([
            'company_id' => $this->companyA->id,
            'date' => now()->format('Y-m-d'),
            'meter' => 'notifications_sms',
            'total_qty' => 20,
        ]);

        $this->actingAs($this->superAdmin)
            ->get("/super-admin/usage/{$this->companyA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/usage/show')
                ->where('company.id', $this->companyA->id)
                ->where('company.name', $this->companyA->name)
                ->has('daily')
                ->has('totals')
                ->has('period')
                ->has('from')
                ->has('to')
            );
    }

    // ── UsageController: export ─────────────────────────────────────

    public function test_usage_export_returns_csv(): void
    {
        UsageDailySummary::factory()->create([
            'company_id' => $this->companyA->id,
            'date' => now()->format('Y-m-d'),
            'meter' => 'alerts_processed',
            'total_qty' => 5,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->get('/super-admin/usage/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Company,Date,Meter,Quantity', $content);
        $this->assertStringContainsString($this->companyA->name, $content);
    }

    // ── AuditController: index ──────────────────────────────────────

    public function test_audit_index_returns_events(): void
    {
        DomainEvent::factory()->count(3)->create([
            'company_id' => $this->companyA->id,
        ]);

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/audit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/audit/index')
                ->has('events.data', 3)
                ->has('companies')
                ->has('entityTypes')
                ->has('eventTypes')
                ->has('filters')
            );
    }

    public function test_audit_index_filters_by_company(): void
    {
        DomainEvent::factory()->count(2)->create(['company_id' => $this->companyA->id]);
        DomainEvent::factory()->count(3)->create(['company_id' => $this->companyB->id]);

        $this->actingAs($this->superAdmin)
            ->get("/super-admin/audit?company_id={$this->companyA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/audit/index')
                ->has('events.data', 2)
            );
    }

    // ── AuditController: export ─────────────────────────────────────

    public function test_audit_export_returns_csv(): void
    {
        DomainEvent::factory()->create([
            'company_id' => $this->companyA->id,
            'entity_type' => 'alert',
            'event_type' => 'alert.created',
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->get('/super-admin/audit/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Entity Type', $content);
        $this->assertStringContainsString('alert.created', $content);
    }
}
