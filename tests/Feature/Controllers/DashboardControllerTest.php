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

    // =========================================================================
    // Super admin sees all companies
    // =========================================================================

    public function test_super_admin_sees_all_alerts_across_companies(): void
    {
        $superAdmin = $this->setUpSuperAdmin();

        $this->createCompletedAlert($this->company);

        [$otherCompany] = $this->createOtherTenant();
        $this->createCompletedAlert($otherCompany);
        $this->createCompletedAlert($otherCompany);

        $this->actingAs($superAdmin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('isSuperAdmin', true)
                ->where('samsaraStats.total', 3)
            );
    }

    public function test_super_admin_sees_user_stats_with_admin_count(): void
    {
        $superAdmin = $this->setUpSuperAdmin();

        $this->actingAs($superAdmin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->has('usersStats.total')
                ->has('usersStats.active')
                ->has('usersStats.admins')
            );
    }

    public function test_regular_user_does_not_see_admin_count_in_user_stats(): void
    {
        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('isSuperAdmin', false)
                ->has('usersStats.total')
                ->has('usersStats.active')
                ->missing('usersStats.admins')
            );
    }

    // =========================================================================
    // Onboarding status
    // =========================================================================

    public function test_regular_user_sees_onboarding_status(): void
    {
        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->has('onboardingStatus')
            );
    }

    public function test_super_admin_sees_null_onboarding_status(): void
    {
        $superAdmin = $this->setUpSuperAdmin();

        $this->actingAs($superAdmin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('onboardingStatus', null)
            );
    }

    // =========================================================================
    // All expected props are present
    // =========================================================================

    public function test_dashboard_includes_all_expected_props(): void
    {
        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->has('samsaraStats')
                ->has('vehiclesStats')
                ->has('contactsStats')
                ->has('usersStats')
                ->has('conversationsStats')
                ->has('eventsBySeverity')
                ->has('eventsByAiStatus')
                ->has('eventsByDay')
                ->has('eventsByType')
                ->has('recentEvents')
                ->has('criticalEvents')
                ->has('eventsNeedingAttention')
                ->has('recentConversations')
                ->has('operationalStatus')
                ->has('trends')
                ->has('attentionQueue')
            );
    }

    // =========================================================================
    // Stats shape
    // =========================================================================

    public function test_samsara_stats_has_expected_keys(): void
    {
        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('samsaraStats.total')
                ->has('samsaraStats.today')
                ->has('samsaraStats.thisWeek')
                ->has('samsaraStats.critical')
                ->has('samsaraStats.pending')
                ->has('samsaraStats.processing')
                ->has('samsaraStats.investigating')
                ->has('samsaraStats.completed')
                ->has('samsaraStats.failed')
                ->has('samsaraStats.needsHumanAttention')
            );
    }

    public function test_contacts_stats_has_expected_keys(): void
    {
        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('contactsStats.total')
                ->has('contactsStats.active')
                ->has('contactsStats.default')
            );
    }

    public function test_conversations_stats_has_expected_keys(): void
    {
        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('conversationsStats.total')
                ->has('conversationsStats.today')
                ->has('conversationsStats.thisWeek')
            );
    }

    // =========================================================================
    // Operational status and trends
    // =========================================================================

    public function test_operational_status_has_expected_keys(): void
    {
        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('operationalStatus.alerts_open')
                ->has('operationalStatus.sla_breaches')
                ->has('operationalStatus.needs_attention')
                ->has('operationalStatus.alerts_today')
            );
    }

    public function test_trends_include_alerts_and_notifications_per_day(): void
    {
        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('trends.alerts_per_day', 14)
                ->has('trends.notifications_per_day', 14)
            );
    }

    // =========================================================================
    // Pipeline health for regular user
    // =========================================================================

    public function test_pipeline_health_is_present_for_regular_user(): void
    {
        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('pipelineHealth')
            );
    }

    public function test_pipeline_health_is_null_for_super_admin(): void
    {
        $superAdmin = $this->setUpSuperAdmin();

        $this->actingAs($superAdmin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pipelineHealth', null)
            );
    }

    // =========================================================================
    // Recent events and critical events
    // =========================================================================

    public function test_recent_events_limited_to_10(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->createCompletedAlert($this->company);
        }

        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recentEvents', 10)
            );
    }

    public function test_critical_events_limited_to_5(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->createCriticalPanicAlert($this->company);
        }

        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('criticalEvents', 5)
            );
    }

    // =========================================================================
    // Events by day includes 7 days
    // =========================================================================

    public function test_events_by_day_has_7_entries(): void
    {
        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('eventsByDay', 7)
            );
    }
}
