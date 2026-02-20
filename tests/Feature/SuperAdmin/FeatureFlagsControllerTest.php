<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class FeatureFlagsControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin = $this->setUpSuperAdmin();
    }

    public function test_index_shows_feature_flags_matrix(): void
    {
        Company::factory()->count(2)->create();

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/feature-flags')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('super-admin/feature-flags/index')
            );
    }

    public function test_update_toggles_feature_flag(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->superAdmin)
            ->put("/super-admin/feature-flags/{$company->id}", [
                'feature' => 'notifications-v2',
                'enabled' => true,
            ])
            ->assertRedirect();
    }

    public function test_regular_user_cannot_access(): void
    {
        $this->setUpTenant();

        $this->actingAs($this->user)
            ->get('/super-admin/feature-flags')
            ->assertForbidden();
    }
}
