<?php

namespace Tests\Feature\AttentionEngine;

use App\Jobs\CheckAttentionSlaJob;
use App\Jobs\EmitDomainEventJob;
use App\Jobs\SendNotificationJob;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Signal;
use App\Models\User;
use App\Services\AttentionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class AttentionEngineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private AttentionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Feature::for($this->company)->activate('ledger-v1');
        Feature::for($this->company)->activate('attention-engine-v1');

        $this->engine = app(AttentionEngine::class);
    }

    private function createEvent(array $overrides = []): Alert
    {
        $signal = Signal::create([
            'company_id' => $this->company->id,
            'source' => 'webhook',
            'samsara_event_id' => 'evt_' . uniqid(),
            'event_type' => 'AlertIncident',
            'event_description' => 'Test alert',
            'vehicle_id' => 'veh_1',
            'vehicle_name' => 'T-001',
            'severity' => $overrides['severity'] ?? 'critical',
            'occurred_at' => $overrides['occurred_at'] ?? now(),
            'raw_payload' => [],
        ]);

        return Alert::create(array_merge([
            'company_id' => $this->company->id,
            'signal_id' => $signal->id,
            'event_description' => 'Test alert',
            'severity' => 'critical',
            'occurred_at' => now(),
            'ai_status' => 'completed',
            'human_status' => 'pending',
            'risk_escalation' => 'call',
            'verdict' => 'real_panic',
        ], $overrides));
    }

    // =========================================================
    // Initialization tests
    // =========================================================

    public function test_initializes_attention_for_critical_event(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $event = $this->createEvent(['severity' => 'critical']);

        $this->engine->initializeAttention($event);

        $event->refresh();
        $this->assertEquals(Alert::ATTENTION_NEEDS_ATTENTION, $event->attention_state);
        $this->assertEquals(Alert::ACK_PENDING, $event->ack_status);
        $this->assertNotNull($event->ack_due_at);
        $this->assertNotNull($event->resolve_due_at);
        $this->assertNotNull($event->next_escalation_at);
        $this->assertEquals(0, $event->escalation_level);
        $this->assertEquals(0, $event->escalation_count);

        Bus::assertDispatched(EmitDomainEventJob::class, fn ($j) => $j->eventType === 'alert.attention_initialized');
    }

    public function test_calculates_sla_deadlines_from_company_config(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $event = $this->createEvent(['severity' => 'critical']);

        $this->travelTo(now());
        $this->engine->initializeAttention($event);
        $event->refresh();

        $this->assertNotNull($event->ack_due_at);
        $this->assertNotNull($event->resolve_due_at);
        $this->assertTrue(
            $event->ack_due_at->gte(now()->addMinutes(4)->addSeconds(59)),
            "ack_due_at ({$event->ack_due_at}) should be ~5 min from now"
        );
        $this->assertTrue(
            $event->resolve_due_at->gte(now()->addMinutes(29)->addSeconds(59)),
            "resolve_due_at ({$event->resolve_due_at}) should be ~30 min from now"
        );
    }

    public function test_skips_initialization_for_info_monitor_events(): void
    {
        $event = $this->createEvent([
            'severity' => 'info',
            'risk_escalation' => 'monitor',
            'verdict' => 'no_action_needed',
        ]);

        $this->engine->initializeAttention($event);

        $event->refresh();
        $this->assertNull($event->attention_state);
    }

    public function test_does_not_reinitialize_if_already_set(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $event = $this->createEvent();
        $this->engine->initializeAttention($event);

        $event->refresh();
        $originalAckDue = $event->ack_due_at->toIso8601String();

        $this->engine->initializeAttention($event);

        $event->refresh();
        $this->assertEquals($originalAckDue, $event->ack_due_at->toIso8601String());
    }

    public function test_skips_when_feature_flag_inactive(): void
    {
        Feature::for($this->company)->deactivate('attention-engine-v1');

        $event = $this->createEvent();
        $this->engine->initializeAttention($event);

        $event->refresh();
        $this->assertNull($event->attention_state);
    }

    public function test_initializes_for_warning_severity_with_warn_escalation(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $event = $this->createEvent([
            'severity' => 'warning',
            'risk_escalation' => 'warn',
            'verdict' => 'needs_review',
        ]);

        $this->engine->initializeAttention($event);

        $event->refresh();
        $this->assertEquals(Alert::ATTENTION_NEEDS_ATTENTION, $event->attention_state);
        $this->assertTrue($event->ack_due_at->greaterThanOrEqualTo(now()->addMinutes(14)));
    }

    // =========================================================
    // ACK tests
    // =========================================================

    public function test_acknowledge_updates_event_state(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $event = $this->createEvent();
        $this->engine->initializeAttention($event);
        $event->refresh();

        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->engine->acknowledge($event, $user);

        $event->refresh();
        $this->assertEquals(Alert::ACK_ACKED, $event->ack_status);
        $this->assertNotNull($event->acked_at);
        $this->assertEquals(Alert::ATTENTION_IN_PROGRESS, $event->attention_state);
        $this->assertNull($event->next_escalation_at);

        Bus::assertDispatched(EmitDomainEventJob::class, fn ($j) => $j->eventType === 'alert.acked');
    }

    public function test_acknowledge_is_idempotent(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $event = $this->createEvent();
        $this->engine->initializeAttention($event);
        $event->refresh();

        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->engine->acknowledge($event, $user);
        $firstAckedAt = $event->fresh()->acked_at;

        $this->engine->acknowledge($event->fresh(), $user);
        $this->assertEquals($firstAckedAt->toIso8601String(), $event->fresh()->acked_at->toIso8601String());
    }

    // =========================================================
    // Assignment tests
    // =========================================================

    public function test_assign_owner_user(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $event = $this->createEvent();
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $assignedBy = User::factory()->create(['company_id' => $this->company->id]);

        $this->engine->assignOwner($event, $user->id, null, $assignedBy);

        $event->refresh();
        $this->assertEquals($user->id, $event->owner_user_id);
        $this->assertNull($event->owner_contact_id);

        Bus::assertDispatched(EmitDomainEventJob::class, fn ($j) => $j->eventType === 'alert.assigned');
    }

    // =========================================================
    // Escalation tests
    // =========================================================

    public function test_escalate_increments_level_and_dispatches_notification(): void
    {
        Bus::fake([SendNotificationJob::class, EmitDomainEventJob::class]);

        Contact::factory()->monitoringTeam()->forCompany($this->company)->create([
            'phone' => '+5215512345678',
        ]);

        $event = $this->createEvent();
        $this->engine->initializeAttention($event);
        $event->refresh();

        $this->engine->escalate($event);

        $event->refresh();
        $this->assertEquals(1, $event->escalation_level);
        $this->assertEquals(1, $event->escalation_count);
        $this->assertNotNull($event->next_escalation_at);

        Bus::assertDispatched(SendNotificationJob::class, function ($job) {
            return $job->decision['is_escalation'] === true
                && $job->decision['should_notify'] === true;
        });

        Bus::assertDispatched(EmitDomainEventJob::class, fn ($j) => $j->eventType === 'alert.escalated');
    }

    public function test_escalate_respects_max_escalations(): void
    {
        Bus::fake([SendNotificationJob::class, EmitDomainEventJob::class]);

        $event = $this->createEvent();
        $this->engine->initializeAttention($event);
        $event->refresh();

        $event->update([
            'escalation_count' => 3,
            'escalation_level' => 2,
        ]);

        $this->engine->escalate($event);

        $event->refresh();
        $this->assertNull($event->next_escalation_at);
        $this->assertEquals(3, $event->escalation_count);

        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    public function test_escalation_level_maps_to_matrix_keys(): void
    {
        $event = $this->createEvent();

        $event->escalation_level = 0;
        $this->assertEquals('warn', $event->getEscalationMatrixKey());

        $event->escalation_level = 1;
        $this->assertEquals('call', $event->getEscalationMatrixKey());

        $event->escalation_level = 2;
        $this->assertEquals('emergency', $event->getEscalationMatrixKey());
    }

    // =========================================================
    // Close attention tests
    // =========================================================

    public function test_close_attention_sets_state_and_resolved_at(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $event = $this->createEvent();
        $this->engine->initializeAttention($event);
        $event->refresh();

        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->engine->closeAttention($event, $user, 'Issue resolved by operator');

        $event->refresh();
        $this->assertEquals(Alert::ATTENTION_CLOSED, $event->attention_state);
        $this->assertNotNull($event->resolved_at);
        $this->assertNull($event->next_escalation_at);

        Bus::assertDispatched(EmitDomainEventJob::class, fn ($j) => $j->eventType === 'alert.attention_closed');
    }

    public function test_close_attention_is_idempotent(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $event = $this->createEvent();
        $this->engine->initializeAttention($event);
        $event->refresh();

        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->engine->closeAttention($event, $user, 'Resolved');

        $count = EmitDomainEventJob::class;
        $this->engine->closeAttention($event->fresh(), $user, 'Resolved again');

        $event->refresh();
        $this->assertEquals(Alert::ATTENTION_CLOSED, $event->attention_state);
    }

    // =========================================================
    // Bulk escalation (CheckAttentionSlaJob) tests
    // =========================================================

    public function test_check_and_escalate_overdue_finds_events(): void
    {
        Bus::fake([SendNotificationJob::class, EmitDomainEventJob::class]);

        Contact::factory()->monitoringTeam()->forCompany($this->company)->create([
            'phone' => '+5215512345678',
        ]);

        $event = $this->createEvent();
        $this->engine->initializeAttention($event);

        $event->update([
            'next_escalation_at' => now()->subMinute(),
        ]);

        $escalated = $this->engine->checkAndEscalateOverdue();

        $this->assertEquals(1, $escalated);
        Bus::assertDispatched(SendNotificationJob::class);
    }

    public function test_check_and_escalate_skips_acked_events(): void
    {
        Bus::fake([SendNotificationJob::class, EmitDomainEventJob::class]);

        $event = $this->createEvent();
        $this->engine->initializeAttention($event);
        $event->refresh();

        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->engine->acknowledge($event, $user);

        $event->update([
            'next_escalation_at' => now()->subMinute(),
        ]);

        $escalated = $this->engine->checkAndEscalateOverdue();

        $this->assertEquals(0, $escalated);
    }

    public function test_scheduled_job_calls_engine(): void
    {
        Bus::fake([SendNotificationJob::class, EmitDomainEventJob::class]);

        Contact::factory()->monitoringTeam()->forCompany($this->company)->create([
            'phone' => '+5215512345678',
        ]);

        $event = $this->createEvent();
        $this->engine->initializeAttention($event);
        $event->update(['next_escalation_at' => now()->subMinute()]);

        $job = new CheckAttentionSlaJob();
        $job->handle($this->engine);

        Bus::assertDispatched(SendNotificationJob::class);
    }

    // =========================================================
    // Model helper tests
    // =========================================================

    public function test_warrants_attention_for_critical(): void
    {
        $event = $this->createEvent(['severity' => 'critical', 'risk_escalation' => 'monitor', 'verdict' => null]);
        $this->assertTrue($event->warrantsAttention());
    }

    public function test_warrants_attention_for_high_risk_verdict(): void
    {
        $event = $this->createEvent([
            'severity' => 'info',
            'risk_escalation' => 'monitor',
            'verdict' => 'confirmed_violation',
        ]);
        $this->assertTrue($event->warrantsAttention());
    }

    public function test_does_not_warrant_attention_for_benign(): void
    {
        $event = $this->createEvent([
            'severity' => 'info',
            'risk_escalation' => 'monitor',
            'verdict' => 'no_action_needed',
        ]);
        $this->assertFalse($event->warrantsAttention());
    }

    public function test_is_overdue_for_ack(): void
    {
        $event = $this->createEvent();
        $event->ack_status = Alert::ACK_PENDING;
        $event->ack_due_at = now()->subMinute();

        $this->assertTrue($event->isOverdueForAck());
    }

    public function test_needs_attention_reflects_attention_state(): void
    {
        $event = $this->createEvent();
        $event->attention_state = Alert::ATTENTION_NEEDS_ATTENTION;
        $this->assertTrue($event->needs_attention);

        $event->attention_state = Alert::ATTENTION_CLOSED;
        $this->assertFalse($event->needs_attention);
    }

    // =========================================================
    // Controller endpoint tests
    // =========================================================

    public function test_assign_endpoint(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $event = $this->createEvent();
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $assignee = User::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($user)->postJson("/api/alerts/{$event->id}/assign", [
            'user_id' => $assignee->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.owner_user_id', $assignee->id);

        $this->assertEquals($assignee->id, $event->fresh()->owner_user_id);
    }

    public function test_close_attention_endpoint(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $event = $this->createEvent();
        $this->engine->initializeAttention($event);

        $user = User::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($user)->postJson("/api/alerts/{$event->id}/close-attention", [
            'reason' => 'Problema resuelto por el operador',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.attention_state', 'closed');

        $this->assertEquals(Alert::ATTENTION_CLOSED, $event->fresh()->attention_state);
    }

    public function test_ack_endpoint_bridges_to_attention_engine(): void
    {
        Bus::fake([EmitDomainEventJob::class]);
        Feature::for($this->company)->activate('notifications-v2');

        $event = $this->createEvent();
        $this->engine->initializeAttention($event);

        $user = User::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($user)->postJson("/api/alerts/{$event->id}/ack");

        $response->assertOk();

        $event->refresh();
        $this->assertEquals(Alert::ACK_ACKED, $event->ack_status);
        $this->assertEquals(Alert::ATTENTION_IN_PROGRESS, $event->attention_state);
    }
}
