<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\AlertAi;
use App\Models\Company;
use App\Models\Signal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class AlertControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_index_returns_inertia_page_with_alerts(): void
    {
        $this->createCompletedAlert($this->company);

        $this->actingAs($this->user)
            ->get('/samsara/alerts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('samsara/events/index')
                ->has('events')
                ->has('stats')
            );
    }

    public function test_index_filters_by_status(): void
    {
        $this->createCompletedAlert($this->company);
        $this->createPendingAlert($this->company);

        $this->actingAs($this->user)
            ->get('/samsara/alerts?status=completed')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('samsara/events/index')
                ->has('events.data', 1)
            );
    }

    public function test_index_filters_by_severity(): void
    {
        $this->createFullAlert($this->company, [], ['severity' => 'critical', 'ai_status' => 'completed']);
        $this->createFullAlert($this->company, [], ['severity' => 'info', 'ai_status' => 'completed']);

        $this->actingAs($this->user)
            ->get('/samsara/alerts?severity=critical')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('samsara/events/index')
                ->has('events.data', 1)
            );
    }

    public function test_index_filters_by_date_range(): void
    {
        $this->createFullAlert($this->company, [], [
            'occurred_at' => now()->subDays(5),
        ]);
        $this->createFullAlert($this->company, [], [
            'occurred_at' => now()->subDays(30),
        ]);

        $this->actingAs($this->user)
            ->get('/samsara/alerts?date_from=' . now()->subWeek()->toDateString())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('samsara/events/index')
                ->has('events.data', 1)
            );
    }

    public function test_index_scoped_to_user_company(): void
    {
        $this->createCompletedAlert($this->company);

        [$otherCompany] = $this->createOtherTenant();
        $this->createCompletedAlert($otherCompany);

        $this->actingAs($this->user)
            ->get('/samsara/alerts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
            );
    }

    public function test_show_returns_alert_detail_page(): void
    {
        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $this->actingAs($this->user)
            ->get("/samsara/alerts/{$alert->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('samsara/events/show')
                ->has('event')
            );
    }

    public function test_show_includes_ai_data_and_activities(): void
    {
        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $this->actingAs($this->user)
            ->get("/samsara/alerts/{$alert->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('samsara/events/show')
                ->has('event')
                ->has('event.timeline')
            );
    }

    public function test_show_returns_404_for_other_company_alert(): void
    {
        [$otherCompany] = $this->createOtherTenant();
        ['alert' => $otherAlert] = $this->createCompletedAlert($otherCompany);

        $this->actingAs($this->user)
            ->get("/samsara/alerts/{$otherAlert->id}")
            ->assertNotFound();
    }

    public function test_analytics_returns_json_stats(): void
    {
        $this->createCompletedAlert($this->company);

        $this->actingAs($this->user)
            ->getJson('/api/events/analytics')
            ->assertOk()
            ->assertJsonStructure([
                'period_days',
                'summary' => ['total_events', 'false_positives', 'real_alerts'],
                'events_by_type',
                'events_by_severity',
            ]);
    }

    public function test_unauthenticated_user_redirected(): void
    {
        $this->get('/samsara/alerts')
            ->assertRedirect('/login');
    }
}
