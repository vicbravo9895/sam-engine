<?php

namespace Tests\Feature\Controllers;

use App\Models\NotificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_index_returns_notifications_page(): void
    {
        $this->actingAs($this->user)
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('notifications/index')
            );
    }

    public function test_index_shows_company_notifications(): void
    {
        ['alert' => $alert] = $this->createCompletedAlert($this->company);
        NotificationResult::factory()->sms()->create(['alert_id' => $alert->id]);

        $this->actingAs($this->user)
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('notifications/index')
                ->has('results.data', 1)
            );
    }

    public function test_stats_returns_notification_stats(): void
    {
        ['alert' => $alert] = $this->createCompletedAlert($this->company);
        NotificationResult::factory()->sms()->successful()->create(['alert_id' => $alert->id]);

        $this->actingAs($this->user)
            ->getJson('/api/notifications/stats')
            ->assertOk()
            ->assertJsonStructure(['daily']);
    }

    public function test_scoped_to_company(): void
    {
        ['alert' => $alert] = $this->createCompletedAlert($this->company);
        NotificationResult::factory()->create(['alert_id' => $alert->id]);

        [$otherCompany] = $this->createOtherTenant();
        ['alert' => $otherAlert] = $this->createCompletedAlert($otherCompany);
        NotificationResult::factory()->count(3)->create(['alert_id' => $otherAlert->id]);

        $this->actingAs($this->user)
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('results.data', 1)
            );
    }
}
