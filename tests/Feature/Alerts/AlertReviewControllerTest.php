<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\AlertActivity;
use App\Models\AlertComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class AlertReviewControllerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    public function test_update_status_changes_human_status(): void
    {
        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $this->actingAs($this->user)
            ->patchJson("/api/alerts/{$alert->id}/status", [
                'status' => 'reviewed',
            ])
            ->assertOk();

        $this->assertDatabaseHas('alerts', [
            'id' => $alert->id,
            'human_status' => 'reviewed',
            'reviewed_by_id' => $this->user->id,
        ]);
    }

    public function test_update_status_creates_activity(): void
    {
        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $this->actingAs($this->user)
            ->patchJson("/api/alerts/{$alert->id}/status", [
                'status' => 'reviewed',
            ])
            ->assertOk();

        $this->assertDatabaseHas('alert_activities', [
            'alert_id' => $alert->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_add_comment_creates_comment(): void
    {
        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $this->actingAs($this->user)
            ->postJson("/api/alerts/{$alert->id}/comments", [
                'content' => 'This is a test comment.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('alert_comments', [
            'alert_id' => $alert->id,
            'user_id' => $this->user->id,
            'content' => 'This is a test comment.',
        ]);
    }

    public function test_get_comments_returns_alert_comments(): void
    {
        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        AlertComment::factory()->create([
            'alert_id' => $alert->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'content' => 'Comment 1',
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/alerts/{$alert->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_get_activities_returns_timeline(): void
    {
        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        AlertActivity::factory()->create([
            'alert_id' => $alert->id,
            'company_id' => $this->company->id,
            'action' => 'status_changed',
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/alerts/{$alert->id}/activities")
            ->assertOk()
            ->assertJsonStructure(['success', 'data']);
    }

    public function test_acknowledge_updates_ack_status(): void
    {
        Feature::define('notifications-v2', fn () => true);
        Feature::define('attention-engine-v1', fn () => true);

        ['alert' => $alert] = $this->createCompletedAlert($this->company);
        $alert->update([
            'attention_state' => Alert::ATTENTION_NEEDS_ATTENTION,
            'ack_status' => Alert::ACK_PENDING,
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/alerts/{$alert->id}/ack")
            ->assertOk();

        $alert->refresh();
        $this->assertEquals(Alert::ACK_ACKED, $alert->ack_status);
    }

    public function test_assign_sets_owner(): void
    {
        Feature::define('attention-engine-v1', fn () => true);

        ['alert' => $alert] = $this->createCompletedAlert($this->company);
        $alert->update(['attention_state' => Alert::ATTENTION_NEEDS_ATTENTION]);

        $this->actingAs($this->user)
            ->postJson("/api/alerts/{$alert->id}/assign", [
                'user_id' => $this->user->id,
            ])
            ->assertOk();

        $alert->refresh();
        $this->assertEquals($this->user->id, $alert->owner_user_id);
    }

    public function test_close_attention_closes_attention_state(): void
    {
        Feature::define('attention-engine-v1', fn () => true);

        ['alert' => $alert] = $this->createCompletedAlert($this->company);
        $alert->update(['attention_state' => Alert::ATTENTION_NEEDS_ATTENTION]);

        $this->actingAs($this->user)
            ->postJson("/api/alerts/{$alert->id}/close-attention", [
                'reason' => 'Resolved manually.',
            ])
            ->assertOk();

        $alert->refresh();
        $this->assertEquals(Alert::ATTENTION_CLOSED, $alert->attention_state);
    }

    public function test_reprocess_dispatches_job(): void
    {
        Bus::fake();

        $superAdmin = $this->setUpSuperAdmin();
        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $this->actingAs($superAdmin)
            ->postJson("/api/alerts/{$alert->id}/reprocess")
            ->assertOk();

        Bus::assertDispatched(\App\Jobs\ProcessAlertJob::class);
    }

    public function test_reprocess_forbidden_for_non_admin(): void
    {
        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $this->actingAs($this->user)
            ->postJson("/api/alerts/{$alert->id}/reprocess")
            ->assertForbidden();
    }

    public function test_update_status_validates_required_status(): void
    {
        ['alert' => $alert] = $this->createCompletedAlert($this->company);

        $this->actingAs($this->user)
            ->patchJson("/api/alerts/{$alert->id}/status", [])
            ->assertUnprocessable();
    }
}
