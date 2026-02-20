<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\DomainEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class AuditControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin = $this->setUpSuperAdmin();
    }

    public function test_index_shows_audit_log(): void
    {
        $company = Company::factory()->create();
        DomainEvent::factory()->create(['company_id' => $company->id]);

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/audit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/audit/index')
            );
    }

    public function test_export_returns_csv(): void
    {
        $company = Company::factory()->create();
        DomainEvent::factory()->create(['company_id' => $company->id]);

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/audit/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_regular_user_cannot_access(): void
    {
        $this->setUpTenant();

        $this->actingAs($this->user)
            ->get('/super-admin/audit')
            ->assertForbidden();
    }
}
