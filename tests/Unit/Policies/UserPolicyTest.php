<?php

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    private UserPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        $this->policy = new UserPolicy();
    }

    // ── viewAny ──────────────────────────────────────────

    public function test_view_any_allowed_for_admin(): void
    {
        $admin = User::factory()->admin()->forCompany($this->company)->create();

        $this->assertTrue($this->policy->viewAny($admin));
    }

    public function test_view_any_allowed_for_manager(): void
    {
        $manager = User::factory()->manager()->forCompany($this->company)->create();

        $this->assertTrue($this->policy->viewAny($manager));
    }

    public function test_view_any_denied_for_regular_user(): void
    {
        $this->assertFalse($this->policy->viewAny($this->user));
    }

    // ── create ───────────────────────────────────────────

    public function test_create_allowed_for_admin(): void
    {
        $admin = User::factory()->admin()->forCompany($this->company)->create();

        $this->assertTrue($this->policy->create($admin));
    }

    public function test_create_denied_for_regular_user(): void
    {
        $this->assertFalse($this->policy->create($this->user));
    }

    // ── update ───────────────────────────────────────────

    public function test_update_allowed_for_admin_same_company(): void
    {
        $admin = User::factory()->admin()->forCompany($this->company)->create();
        $target = User::factory()->forCompany($this->company)->create();

        $this->assertTrue($this->policy->update($admin, $target));
    }

    public function test_update_denied_for_different_company(): void
    {
        $admin = User::factory()->admin()->forCompany($this->company)->create();
        [$otherCompany] = $this->createOtherTenant();
        $target = User::factory()->forCompany($otherCompany)->create();

        $this->assertFalse($this->policy->update($admin, $target));
    }

    public function test_update_denied_for_regular_user(): void
    {
        $target = User::factory()->forCompany($this->company)->create();

        $this->assertFalse($this->policy->update($this->user, $target));
    }

    // ── delete ───────────────────────────────────────────

    public function test_delete_allowed_for_admin_same_company(): void
    {
        $admin = User::factory()->admin()->forCompany($this->company)->create();
        $target = User::factory()->forCompany($this->company)->create();

        $this->assertTrue($this->policy->delete($admin, $target));
    }

    public function test_delete_denied_for_different_company(): void
    {
        $admin = User::factory()->admin()->forCompany($this->company)->create();
        [$otherCompany] = $this->createOtherTenant();
        $target = User::factory()->forCompany($otherCompany)->create();

        $this->assertFalse($this->policy->delete($admin, $target));
    }

    public function test_delete_denied_for_self(): void
    {
        $admin = User::factory()->admin()->forCompany($this->company)->create();

        $this->assertFalse($this->policy->delete($admin, $admin));
    }

    public function test_delete_denied_for_non_admin_deleting_admin(): void
    {
        $manager = User::factory()->manager()->forCompany($this->company)->create();
        $admin = User::factory()->admin()->forCompany($this->company)->create();

        $this->assertFalse($this->policy->delete($manager, $admin));
    }

    public function test_delete_allowed_for_admin_deleting_admin(): void
    {
        $admin1 = User::factory()->admin()->forCompany($this->company)->create();
        $admin2 = User::factory()->admin()->forCompany($this->company)->create();

        $this->assertTrue($this->policy->delete($admin1, $admin2));
    }

    public function test_delete_denied_for_regular_user(): void
    {
        $target = User::factory()->forCompany($this->company)->create();

        $this->assertFalse($this->policy->delete($this->user, $target));
    }

    // ── changeRole ───────────────────────────────────────

    public function test_change_role_allowed_for_admin_same_company(): void
    {
        $admin = User::factory()->admin()->forCompany($this->company)->create();
        $target = User::factory()->forCompany($this->company)->create();

        $this->assertTrue($this->policy->changeRole($admin, $target));
    }

    public function test_change_role_denied_for_self(): void
    {
        $admin = User::factory()->admin()->forCompany($this->company)->create();

        $this->assertFalse($this->policy->changeRole($admin, $admin));
    }

    public function test_change_role_denied_for_different_company(): void
    {
        $admin = User::factory()->admin()->forCompany($this->company)->create();
        [$otherCompany] = $this->createOtherTenant();
        $target = User::factory()->forCompany($otherCompany)->create();

        $this->assertFalse($this->policy->changeRole($admin, $target));
    }

    public function test_change_role_denied_for_non_admin_targeting_admin(): void
    {
        $manager = User::factory()->manager()->forCompany($this->company)->create();
        $admin = User::factory()->admin()->forCompany($this->company)->create();

        $this->assertFalse($this->policy->changeRole($manager, $admin));
    }

    public function test_change_role_denied_for_regular_user(): void
    {
        $target = User::factory()->forCompany($this->company)->create();

        $this->assertFalse($this->policy->changeRole($this->user, $target));
    }
}
