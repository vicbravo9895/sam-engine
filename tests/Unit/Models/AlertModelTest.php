<?php

namespace Tests\Unit\Models;

use App\Models\Alert;
use App\Models\AlertAi;
use App\Models\Company;
use App\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;
use Tests\Traits\CreatesAlertPipeline;

class AlertModelTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant, CreatesAlertPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
    }

    private function createAlert(array $overrides = []): Alert
    {
        $signal = Signal::factory()->create(['company_id' => $this->company->id]);
        return Alert::factory()->create(array_merge(
            ['company_id' => $this->company->id, 'signal_id' => $signal->id],
            $overrides
        ));
    }

    public function test_get_verdict_label_returns_spanish_labels(): void
    {
        $labels = [
            Alert::VERDICT_REAL_PANIC => 'Pánico real',
            Alert::VERDICT_CONFIRMED_VIOLATION => 'Violación confirmada',
            Alert::VERDICT_NEEDS_REVIEW => 'Requiere revisión',
            Alert::VERDICT_UNCERTAIN => 'Incierto',
            Alert::VERDICT_LIKELY_FALSE_POSITIVE => 'Probable falso positivo',
            Alert::VERDICT_NO_ACTION_NEEDED => 'No requiere acción',
            Alert::VERDICT_RISK_DETECTED => 'Riesgo detectado',
        ];

        foreach ($labels as $verdict => $expected) {
            $alert = $this->createAlert(['verdict' => $verdict]);
            $this->assertEquals($expected, $alert->getVerdictLabel());
        }
    }

    public function test_get_likelihood_label_returns_spanish_labels(): void
    {
        $labels = [
            Alert::LIKELIHOOD_HIGH => 'Alta',
            Alert::LIKELIHOOD_MEDIUM => 'Media',
            Alert::LIKELIHOOD_LOW => 'Baja',
        ];

        foreach ($labels as $likelihood => $expected) {
            $alert = $this->createAlert(['likelihood' => $likelihood]);
            $this->assertEquals($expected, $alert->getLikelihoodLabel());
        }
    }

    public function test_get_alert_kind_label_returns_spanish_labels(): void
    {
        $labels = [
            Alert::ALERT_KIND_PANIC => 'Pánico',
            Alert::ALERT_KIND_SAFETY => 'Seguridad',
            Alert::ALERT_KIND_TAMPERING => 'Manipulación',
            Alert::ALERT_KIND_CONNECTIVITY => 'Conectividad',
            Alert::ALERT_KIND_UNKNOWN => 'Desconocido',
        ];

        foreach ($labels as $kind => $expected) {
            $alert = $this->createAlert(['alert_kind' => $kind]);
            $this->assertEquals($expected, $alert->getAlertKindLabel());
        }
    }

    public function test_is_human_reviewed_returns_true_when_not_pending(): void
    {
        $alert = $this->createAlert(['human_status' => Alert::HUMAN_STATUS_REVIEWED]);
        $this->assertTrue($alert->isHumanReviewed());
    }

    public function test_is_human_reviewed_returns_false_when_pending(): void
    {
        $alert = $this->createAlert(['human_status' => Alert::HUMAN_STATUS_PENDING]);
        $this->assertFalse($alert->isHumanReviewed());
    }

    public function test_is_critical_returns_true_for_critical_severity(): void
    {
        $alert = $this->createAlert(['severity' => Alert::SEVERITY_CRITICAL]);
        $this->assertTrue($alert->isCritical());
    }

    public function test_is_critical_returns_false_for_warning(): void
    {
        $alert = $this->createAlert(['severity' => Alert::SEVERITY_WARNING]);
        $this->assertFalse($alert->isCritical());
    }

    public function test_is_processed_returns_true_for_completed_and_failed(): void
    {
        $completed = $this->createAlert(['ai_status' => Alert::STATUS_COMPLETED]);
        $failed = $this->createAlert(['ai_status' => Alert::STATUS_FAILED]);
        $this->assertTrue($completed->isProcessed());
        $this->assertTrue($failed->isProcessed());
    }

    public function test_is_processed_returns_false_for_pending(): void
    {
        $alert = $this->createAlert(['ai_status' => Alert::STATUS_PENDING]);
        $this->assertFalse($alert->isProcessed());
    }

    public function test_is_proactive_returns_true_when_proactive_flag_set(): void
    {
        $alert = $this->createAlert(['proactive_flag' => true]);
        $this->assertTrue($alert->isProactive());
    }

    public function test_is_proactive_returns_false_when_proactive_flag_false(): void
    {
        $alert = $this->createAlert(['proactive_flag' => false]);
        $this->assertFalse($alert->isProactive());
    }

    public function test_is_probable_false_positive_returns_true_for_verdict(): void
    {
        $alert = $this->createAlert(['verdict' => Alert::VERDICT_LIKELY_FALSE_POSITIVE]);
        $this->assertTrue($alert->isProbableFalsePositive());
    }

    public function test_is_probable_false_positive_returns_true_for_human_status(): void
    {
        $alert = $this->createAlert(['human_status' => Alert::HUMAN_STATUS_FALSE_POSITIVE]);
        $this->assertTrue($alert->isProbableFalsePositive());
    }

    public function test_has_high_risk_verdict_returns_true_for_risk_verdicts(): void
    {
        $alert = $this->createAlert(['verdict' => Alert::VERDICT_REAL_PANIC]);
        $this->assertTrue($alert->hasHighRiskVerdict());
    }

    public function test_has_high_risk_verdict_returns_false_for_no_action_needed(): void
    {
        $alert = $this->createAlert(['verdict' => Alert::VERDICT_NO_ACTION_NEEDED]);
        $this->assertFalse($alert->hasHighRiskVerdict());
    }

    public function test_warrants_attention_returns_true_for_critical_severity(): void
    {
        $alert = $this->createAlert(['severity' => Alert::SEVERITY_CRITICAL]);
        $this->assertTrue($alert->warrantsAttention());
    }

    public function test_warrants_attention_returns_true_for_high_risk_escalation(): void
    {
        $alert = $this->createAlert(['risk_escalation' => Alert::RISK_EMERGENCY]);
        $this->assertTrue($alert->warrantsAttention());
    }

    public function test_requires_urgent_escalation_returns_true_for_call_and_emergency(): void
    {
        $call = $this->createAlert(['risk_escalation' => Alert::RISK_CALL]);
        $emergency = $this->createAlert(['risk_escalation' => Alert::RISK_EMERGENCY]);
        $this->assertTrue($call->requiresUrgentEscalation());
        $this->assertTrue($emergency->requiresUrgentEscalation());
    }

    public function test_requires_urgent_escalation_returns_false_for_monitor(): void
    {
        $alert = $this->createAlert(['risk_escalation' => Alert::RISK_MONITOR]);
        $this->assertFalse($alert->requiresUrgentEscalation());
    }

    public function test_has_owner_returns_true_when_owner_user_set(): void
    {
        $alert = $this->createAlert(['owner_user_id' => $this->user->id]);
        $this->assertTrue($alert->hasOwner());
    }

    public function test_has_owner_returns_false_when_no_owner(): void
    {
        $alert = $this->createAlert(['owner_user_id' => null, 'owner_contact_id' => null]);
        $this->assertFalse($alert->hasOwner());
    }

    public function test_is_overdue_for_ack_returns_true_when_pending_and_past_due(): void
    {
        $alert = $this->createAlert([
            'ack_status' => Alert::ACK_PENDING,
            'ack_due_at' => now()->subMinute(),
        ]);
        $this->assertTrue($alert->isOverdueForAck());
    }

    public function test_is_overdue_for_ack_returns_false_when_acked(): void
    {
        $alert = $this->createAlert([
            'ack_status' => Alert::ACK_ACKED,
            'ack_due_at' => now()->subMinute(),
        ]);
        $this->assertFalse($alert->isOverdueForAck());
    }

    public function test_is_overdue_for_resolution_returns_true_when_past_due(): void
    {
        $alert = $this->createAlert([
            'attention_state' => Alert::ATTENTION_NEEDS_ATTENTION,
            'resolve_due_at' => now()->subMinute(),
        ]);
        $this->assertTrue($alert->isOverdueForResolution());
    }

    public function test_is_overdue_for_resolution_returns_false_when_closed(): void
    {
        $alert = $this->createAlert([
            'attention_state' => Alert::ATTENTION_CLOSED,
            'resolve_due_at' => now()->subMinute(),
        ]);
        $this->assertFalse($alert->isOverdueForResolution());
    }

    public function test_get_escalation_matrix_key_returns_emergency_for_level_2(): void
    {
        $alert = $this->createAlert(['escalation_level' => 2]);
        $this->assertEquals(Alert::RISK_EMERGENCY, $alert->getEscalationMatrixKey());
    }

    public function test_get_escalation_matrix_key_returns_call_for_level_1(): void
    {
        $alert = $this->createAlert(['escalation_level' => 1]);
        $this->assertEquals(Alert::RISK_CALL, $alert->getEscalationMatrixKey());
    }

    public function test_get_escalation_matrix_key_returns_warn_for_level_0(): void
    {
        $alert = $this->createAlert(['escalation_level' => 0]);
        $this->assertEquals(Alert::RISK_WARN, $alert->getEscalationMatrixKey());
    }

    public function test_get_max_investigations_returns_default_without_company(): void
    {
        $this->assertEquals(3, Alert::getMaxInvestigations(null));
    }

    public function test_get_max_investigations_uses_company_config(): void
    {
        $this->company->update([
            'settings' => ['ai_config' => ['usage_limits' => ['max_revalidations_per_event' => 5]]],
        ]);
        $this->assertEquals(5, Alert::getMaxInvestigations($this->company));
    }

    public function test_scope_for_company_filters_by_company(): void
    {
        $this->createAlert();
        [$otherCompany] = $this->createOtherTenant();
        $signal = Signal::factory()->create(['company_id' => $otherCompany->id]);
        Alert::factory()->create(['company_id' => $otherCompany->id, 'signal_id' => $signal->id]);

        $result = Alert::forCompany($this->company->id)->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_with_status_filters_by_ai_status(): void
    {
        $this->createAlert(['ai_status' => Alert::STATUS_COMPLETED]);
        $this->createAlert(['ai_status' => Alert::STATUS_PENDING]);

        $result = Alert::forCompany($this->company->id)->withStatus(Alert::STATUS_COMPLETED)->get();
        $this->assertCount(1, $result);
        $this->assertEquals(Alert::STATUS_COMPLETED, $result->first()->ai_status);
    }

    public function test_scope_pending_filters_pending_alerts(): void
    {
        $this->createAlert(['ai_status' => Alert::STATUS_PENDING]);
        $this->createAlert(['ai_status' => Alert::STATUS_COMPLETED]);

        $result = Alert::forCompany($this->company->id)->pending()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_completed_filters_completed_alerts(): void
    {
        $this->createAlert(['ai_status' => Alert::STATUS_COMPLETED]);
        $this->createAlert(['ai_status' => Alert::STATUS_FAILED]);

        $result = Alert::forCompany($this->company->id)->completed()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_failed_filters_failed_alerts(): void
    {
        $this->createAlert(['ai_status' => Alert::STATUS_FAILED]);
        $this->createAlert(['ai_status' => Alert::STATUS_COMPLETED]);

        $result = Alert::forCompany($this->company->id)->failed()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_critical_filters_by_severity(): void
    {
        $this->createAlert(['severity' => Alert::SEVERITY_CRITICAL]);
        $this->createAlert(['severity' => Alert::SEVERITY_WARNING]);

        $result = Alert::forCompany($this->company->id)->critical()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_proactive_filters_proactive_alerts(): void
    {
        $this->createAlert(['proactive_flag' => true]);
        $this->createAlert(['proactive_flag' => false]);

        $result = Alert::forCompany($this->company->id)->proactive()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_by_risk_escalation_filters_by_level(): void
    {
        $this->createAlert(['risk_escalation' => Alert::RISK_EMERGENCY]);
        $this->createAlert(['risk_escalation' => Alert::RISK_MONITOR]);

        $result = Alert::forCompany($this->company->id)->byRiskEscalation(Alert::RISK_EMERGENCY)->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_by_dedupe_key_filters_by_key(): void
    {
        $key = 'test-dedupe-key-123';
        $this->createAlert(['dedupe_key' => $key]);
        $this->createAlert(['dedupe_key' => 'other-key']);

        $result = Alert::forCompany($this->company->id)->byDedupeKey($key)->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_human_pending_filters_by_human_status(): void
    {
        $this->createAlert(['human_status' => Alert::HUMAN_STATUS_PENDING]);
        $this->createAlert(['human_status' => Alert::HUMAN_STATUS_REVIEWED]);

        $result = Alert::forCompany($this->company->id)->humanPending()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_human_reviewed_filters_by_human_status(): void
    {
        $this->createAlert(['human_status' => Alert::HUMAN_STATUS_REVIEWED]);
        $this->createAlert(['human_status' => Alert::HUMAN_STATUS_PENDING]);

        $result = Alert::forCompany($this->company->id)->humanReviewed()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_by_verdict_filters_by_verdict(): void
    {
        $this->createAlert(['verdict' => Alert::VERDICT_REAL_PANIC]);
        $this->createAlert(['verdict' => Alert::VERDICT_NO_ACTION_NEEDED]);

        $result = Alert::forCompany($this->company->id)->byVerdict(Alert::VERDICT_REAL_PANIC)->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_by_alert_kind_filters_by_kind(): void
    {
        $this->createAlert(['alert_kind' => Alert::ALERT_KIND_PANIC]);
        $this->createAlert(['alert_kind' => Alert::ALERT_KIND_SAFETY]);

        $result = Alert::forCompany($this->company->id)->byAlertKind(Alert::ALERT_KIND_PANIC)->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_high_confidence_filters_by_threshold(): void
    {
        $this->createAlert(['confidence' => 0.9]);
        $this->createAlert(['confidence' => 0.5]);

        $result = Alert::forCompany($this->company->id)->highConfidence(0.8)->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_panic_alerts_filters_panic_kind(): void
    {
        $this->createAlert(['alert_kind' => Alert::ALERT_KIND_PANIC]);
        $this->createAlert(['alert_kind' => Alert::ALERT_KIND_SAFETY]);

        $result = Alert::forCompany($this->company->id)->panicAlerts()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_safety_alerts_filters_safety_kind(): void
    {
        $this->createAlert(['alert_kind' => Alert::ALERT_KIND_SAFETY]);
        $this->createAlert(['alert_kind' => Alert::ALERT_KIND_PANIC]);

        $result = Alert::forCompany($this->company->id)->safetyAlerts()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_needs_attention_excludes_closed(): void
    {
        $this->createAlert(['attention_state' => Alert::ATTENTION_NEEDS_ATTENTION]);
        $this->createAlert(['attention_state' => Alert::ATTENTION_CLOSED]);

        $result = Alert::forCompany($this->company->id)->needsAttention()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_by_attention_state_filters_by_state(): void
    {
        $this->createAlert(['attention_state' => Alert::ATTENTION_IN_PROGRESS]);
        $this->createAlert(['attention_state' => Alert::ATTENTION_BLOCKED]);

        $result = Alert::forCompany($this->company->id)->byAttentionState(Alert::ATTENTION_IN_PROGRESS)->get();
        $this->assertCount(1, $result);
    }

    public function test_should_revalidate_returns_false_when_not_investigating(): void
    {
        $alert = $this->createAlert(['ai_status' => Alert::STATUS_COMPLETED]);
        $this->assertFalse($alert->shouldRevalidate());
    }

    public function test_should_revalidate_returns_true_when_no_ai_or_next_check(): void
    {
        $alert = $this->createAlert(['ai_status' => Alert::STATUS_INVESTIGATING]);
        $this->assertTrue($alert->shouldRevalidate());
    }

    public function test_should_revalidate_returns_true_when_ai_missing_last_investigation(): void
    {
        ['alert' => $alert] = $this->createFullAlert(
            $this->company,
            [],
            ['ai_status' => Alert::STATUS_INVESTIGATING],
            [
                'last_investigation_at' => null,
                'next_check_minutes' => 15,
            ]
        );

        $this->assertTrue($alert->fresh()->shouldRevalidate());
    }

    public function test_should_revalidate_returns_false_when_before_next_check(): void
    {
        $alert = $this->createAlert(['ai_status' => Alert::STATUS_INVESTIGATING]);
        AlertAi::factory()->create([
            'alert_id' => $alert->id,
            'last_investigation_at' => now()->subMinutes(5),
            'next_check_minutes' => 15,
        ]);

        $this->assertFalse($alert->fresh()->shouldRevalidate());
    }

    // =========================================================================
    // State Transitions
    // =========================================================================

    public function test_mark_as_processing_updates_status(): void
    {
        $alert = $this->createAlert(['ai_status' => Alert::STATUS_PENDING]);
        $alert->markAsProcessing();

        $this->assertEquals(Alert::STATUS_PROCESSING, $alert->fresh()->ai_status);
    }

    public function test_mark_as_completed_updates_alert_and_creates_ai_data(): void
    {
        $alert = $this->createAlert(['ai_status' => Alert::STATUS_PROCESSING]);

        $assessment = [
            'verdict' => Alert::VERDICT_CONFIRMED_VIOLATION,
            'likelihood' => Alert::LIKELIHOOD_HIGH,
            'confidence' => 0.92,
            'reasoning' => 'Clear violation detected.',
            'risk_escalation' => Alert::RISK_WARN,
            'dedupe_key' => 'test-dedupe',
        ];

        $alertContext = [
            'alert_kind' => Alert::ALERT_KIND_SAFETY,
            'proactive_flag' => true,
            'triage_notes' => 'Triage complete.',
        ];

        $execution = ['total_tokens' => 1000, 'cost_estimate' => 0.003];

        $alert->markAsCompleted(
            assessment: $assessment,
            humanMessage: 'Alert resolved.',
            alertContext: $alertContext,
            execution: $execution,
        );

        $fresh = $alert->fresh();
        $this->assertEquals(Alert::STATUS_COMPLETED, $fresh->ai_status);
        $this->assertEquals('Alert resolved.', $fresh->ai_message);
        $this->assertEquals(Alert::VERDICT_CONFIRMED_VIOLATION, $fresh->verdict);
        $this->assertEquals(Alert::LIKELIHOOD_HIGH, $fresh->likelihood);
        $this->assertEquals('0.92', $fresh->confidence);
        $this->assertEquals(Alert::RISK_WARN, $fresh->risk_escalation);
        $this->assertEquals('test-dedupe', $fresh->dedupe_key);
        $this->assertTrue($fresh->proactive_flag);
        $this->assertEquals(Alert::ALERT_KIND_SAFETY, $fresh->alert_kind);

        $ai = $fresh->ai;
        $this->assertNotNull($ai);
        $this->assertEquals($assessment, $ai->ai_assessment);
        $this->assertEquals($alertContext, $ai->alert_context);
    }

    public function test_mark_as_completed_stores_notification_decision(): void
    {
        $alert = $this->createAlert(['ai_status' => Alert::STATUS_PROCESSING]);
        $decision = ['should_notify' => true, 'channels' => ['sms']];

        $alert->markAsCompleted(
            assessment: ['verdict' => Alert::VERDICT_NO_ACTION_NEEDED],
            humanMessage: 'Done.',
            notificationDecision: $decision,
        );

        $fresh = $alert->fresh();
        $this->assertEquals($decision, $fresh->notification_decision_payload);
    }

    public function test_mark_as_investigating_updates_alert_and_ai_record(): void
    {
        ['alert' => $alert, 'ai' => $ai] = $this->createFullAlert(
            $this->company,
            [],
            ['ai_status' => Alert::STATUS_PROCESSING],
            ['investigation_count' => 0],
        );

        $assessment = [
            'verdict' => Alert::VERDICT_UNCERTAIN,
            'likelihood' => Alert::LIKELIHOOD_MEDIUM,
            'confidence' => 0.55,
            'reasoning' => 'Need more data.',
            'monitoring_reason' => 'Observation required.',
        ];

        $alert->markAsInvestigating(
            assessment: $assessment,
            humanMessage: 'Monitoring in progress.',
            nextCheckMinutes: 15,
        );

        $fresh = $alert->fresh();
        $this->assertEquals(Alert::STATUS_INVESTIGATING, $fresh->ai_status);
        $this->assertEquals('Monitoring in progress.', $fresh->ai_message);

        $freshAi = $fresh->ai;
        $this->assertEquals(1, $freshAi->investigation_count);
        $this->assertEquals(15, $freshAi->next_check_minutes);
        $this->assertNotNull($freshAi->last_investigation_at);
    }

    public function test_mark_as_failed_with_existing_ai_updates_error(): void
    {
        ['alert' => $alert] = $this->createFullAlert(
            $this->company,
            [],
            ['ai_status' => Alert::STATUS_PROCESSING],
        );

        $alert->markAsFailed('Connection timeout');

        $fresh = $alert->fresh();
        $this->assertEquals(Alert::STATUS_FAILED, $fresh->ai_status);
        $this->assertEquals('Connection timeout', $fresh->ai->ai_error);
    }

    public function test_mark_as_failed_without_ai_creates_new_ai_record(): void
    {
        $alert = $this->createAlert(['ai_status' => Alert::STATUS_PROCESSING]);
        $this->assertNull($alert->ai);

        $alert->markAsFailed('AI service unavailable');

        $fresh = $alert->fresh();
        $this->assertEquals(Alert::STATUS_FAILED, $fresh->ai_status);
        $this->assertNotNull($fresh->ai);
        $this->assertEquals('AI service unavailable', $fresh->ai->ai_error);
    }

    // =========================================================================
    // Investigation records
    // =========================================================================

    public function test_add_investigation_record_updates_existing_history_entry(): void
    {
        ['alert' => $alert] = $this->createFullAlert(
            $this->company,
            [],
            ['ai_status' => Alert::STATUS_INVESTIGATING],
            [
                'investigation_count' => 1,
                'investigation_history' => [
                    [
                        'investigation_number' => 1,
                        'timestamp' => now()->subMinutes(10)->toIso8601String(),
                    ],
                ],
            ],
        );

        $alert->addInvestigationRecord('Situation improving');

        $freshAi = $alert->fresh()->ai;
        $history = $freshAi->investigation_history;
        $this->assertEquals('Situation improving', $history[0]['ai_reason']);
        $this->assertArrayHasKey('ai_evaluated_at', $history[0]);
    }

    public function test_add_investigation_record_appends_when_no_investigation_number(): void
    {
        ['alert' => $alert] = $this->createFullAlert(
            $this->company,
            [],
            ['ai_status' => Alert::STATUS_INVESTIGATING],
            [
                'investigation_count' => 1,
                'investigation_history' => [
                    ['timestamp' => now()->subMinutes(10)->toIso8601String(), 'reason' => 'old'],
                ],
            ],
        );

        $alert->addInvestigationRecord('New observation');

        $freshAi = $alert->fresh()->ai;
        $history = $freshAi->investigation_history;
        $this->assertCount(2, $history);
        $this->assertEquals('New observation', $history[1]['reason']);
    }

    public function test_add_investigation_record_does_nothing_without_ai(): void
    {
        $alert = $this->createAlert(['ai_status' => Alert::STATUS_INVESTIGATING]);
        $this->assertNull($alert->ai);

        $alert->addInvestigationRecord('Should do nothing');

        $this->assertNull($alert->fresh()->ai);
    }

    // =========================================================================
    // Recommended actions & investigation steps
    // =========================================================================

    public function test_save_and_get_recommended_actions(): void
    {
        $alert = $this->createAlert();
        $actions = ['Contact supervisor', 'Review dashcam', 'Check driver history'];

        $alert->saveRecommendedActions($actions);

        $result = $alert->getRecommendedActionsArray();
        $this->assertEquals($actions, $result);
    }

    public function test_get_recommended_actions_returns_empty_when_none(): void
    {
        $alert = $this->createAlert();

        $this->assertEquals([], $alert->getRecommendedActionsArray());
    }

    public function test_save_recommended_actions_replaces_existing(): void
    {
        $alert = $this->createAlert();
        $alert->saveRecommendedActions(['Old action 1', 'Old action 2']);
        $alert->saveRecommendedActions(['New action only']);

        $this->assertEquals(['New action only'], $alert->getRecommendedActionsArray());
    }

    public function test_save_and_get_investigation_steps(): void
    {
        $alert = $this->createAlert();
        $steps = ['Check GPS data', 'Review camera footage', 'Interview driver'];

        $alert->saveInvestigationSteps($steps);

        $result = $alert->getInvestigationStepsArray();
        $this->assertEquals($steps, $result);
    }

    public function test_get_investigation_steps_returns_empty_when_none(): void
    {
        $alert = $this->createAlert();

        $this->assertEquals([], $alert->getInvestigationStepsArray());
    }

    // =========================================================================
    // Human status
    // =========================================================================

    public function test_set_human_status_updates_status_and_records_activity(): void
    {
        $alert = $this->createAlert(['human_status' => Alert::HUMAN_STATUS_PENDING]);

        $alert->setHumanStatus(Alert::HUMAN_STATUS_REVIEWED, $this->user->id);

        $fresh = $alert->fresh();
        $this->assertEquals(Alert::HUMAN_STATUS_REVIEWED, $fresh->human_status);
        $this->assertEquals($this->user->id, $fresh->reviewed_by_id);
        $this->assertNotNull($fresh->reviewed_at);

        $this->assertDatabaseHas('alert_activities', [
            'alert_id' => $alert->id,
            'user_id' => $this->user->id,
            'action' => 'human_status_changed',
        ]);
    }

    public function test_set_human_status_throws_for_invalid_status(): void
    {
        $alert = $this->createAlert();

        $this->expectException(\InvalidArgumentException::class);
        $alert->setHumanStatus('invalid_status', $this->user->id);
    }

    public function test_add_comment_creates_comment_and_activity(): void
    {
        $alert = $this->createAlert();

        $comment = $alert->addComment($this->user->id, 'This looks suspicious');

        $this->assertDatabaseHas('alert_comments', [
            'alert_id' => $alert->id,
            'user_id' => $this->user->id,
            'content' => 'This looks suspicious',
        ]);

        $this->assertDatabaseHas('alert_activities', [
            'alert_id' => $alert->id,
            'user_id' => $this->user->id,
            'action' => 'comment_added',
        ]);

        $this->assertNotNull($comment->id);
    }

    // =========================================================================
    // Computed: getNeedsAttentionAttribute
    // =========================================================================

    public function test_needs_attention_returns_false_when_closed(): void
    {
        $alert = $this->createAlert(['attention_state' => Alert::ATTENTION_CLOSED]);
        $this->assertFalse($alert->needs_attention);
    }

    public function test_needs_attention_returns_true_when_attention_state_needs_attention(): void
    {
        $alert = $this->createAlert(['attention_state' => Alert::ATTENTION_NEEDS_ATTENTION]);
        $this->assertTrue($alert->needs_attention);
    }

    public function test_needs_attention_returns_false_when_human_reviewed(): void
    {
        $alert = $this->createAlert([
            'attention_state' => null,
            'human_status' => Alert::HUMAN_STATUS_REVIEWED,
            'ai_status' => Alert::STATUS_FAILED,
        ]);
        $this->assertFalse($alert->needs_attention);
    }

    public function test_needs_attention_returns_true_for_failed_status(): void
    {
        $alert = $this->createAlert([
            'attention_state' => null,
            'human_status' => Alert::HUMAN_STATUS_PENDING,
            'ai_status' => Alert::STATUS_FAILED,
        ]);
        $this->assertTrue($alert->needs_attention);
    }

    public function test_needs_attention_returns_true_for_investigating_status(): void
    {
        $alert = $this->createAlert([
            'attention_state' => null,
            'human_status' => Alert::HUMAN_STATUS_PENDING,
            'ai_status' => Alert::STATUS_INVESTIGATING,
        ]);
        $this->assertTrue($alert->needs_attention);
    }

    public function test_needs_attention_returns_true_for_critical_severity(): void
    {
        $alert = $this->createAlert([
            'attention_state' => null,
            'human_status' => Alert::HUMAN_STATUS_PENDING,
            'ai_status' => Alert::STATUS_COMPLETED,
            'severity' => Alert::SEVERITY_CRITICAL,
        ]);
        $this->assertTrue($alert->needs_attention);
    }

    public function test_needs_attention_returns_true_for_urgent_escalation(): void
    {
        $alert = $this->createAlert([
            'attention_state' => null,
            'human_status' => Alert::HUMAN_STATUS_PENDING,
            'ai_status' => Alert::STATUS_COMPLETED,
            'severity' => Alert::SEVERITY_INFO,
            'risk_escalation' => Alert::RISK_EMERGENCY,
        ]);
        $this->assertTrue($alert->needs_attention);
    }

    // =========================================================================
    // getHumanUrgencyLevel
    // =========================================================================

    public function test_human_urgency_low_when_not_pending(): void
    {
        $alert = $this->createAlert(['human_status' => Alert::HUMAN_STATUS_REVIEWED]);
        $this->assertEquals('low', $alert->getHumanUrgencyLevel());
    }

    public function test_human_urgency_high_when_failed(): void
    {
        $alert = $this->createAlert([
            'human_status' => Alert::HUMAN_STATUS_PENDING,
            'ai_status' => Alert::STATUS_FAILED,
        ]);
        $this->assertEquals('high', $alert->getHumanUrgencyLevel());
    }

    public function test_human_urgency_high_when_critical(): void
    {
        $alert = $this->createAlert([
            'human_status' => Alert::HUMAN_STATUS_PENDING,
            'ai_status' => Alert::STATUS_COMPLETED,
            'severity' => Alert::SEVERITY_CRITICAL,
        ]);
        $this->assertEquals('high', $alert->getHumanUrgencyLevel());
    }

    public function test_human_urgency_high_when_urgent_escalation(): void
    {
        $alert = $this->createAlert([
            'human_status' => Alert::HUMAN_STATUS_PENDING,
            'ai_status' => Alert::STATUS_COMPLETED,
            'severity' => Alert::SEVERITY_INFO,
            'risk_escalation' => Alert::RISK_EMERGENCY,
        ]);
        $this->assertEquals('high', $alert->getHumanUrgencyLevel());
    }

    public function test_human_urgency_medium_when_investigating_with_multiple_checks(): void
    {
        ['alert' => $alert] = $this->createFullAlert(
            $this->company,
            [],
            [
                'human_status' => Alert::HUMAN_STATUS_PENDING,
                'ai_status' => Alert::STATUS_INVESTIGATING,
                'severity' => Alert::SEVERITY_INFO,
                'risk_escalation' => Alert::RISK_MONITOR,
            ],
            ['investigation_count' => 3],
        );

        $this->assertEquals('medium', $alert->fresh()->getHumanUrgencyLevel());
    }

    public function test_human_urgency_medium_when_investigating(): void
    {
        $alert = $this->createAlert([
            'human_status' => Alert::HUMAN_STATUS_PENDING,
            'ai_status' => Alert::STATUS_INVESTIGATING,
            'severity' => Alert::SEVERITY_INFO,
            'risk_escalation' => Alert::RISK_MONITOR,
        ]);
        $this->assertEquals('medium', $alert->getHumanUrgencyLevel());
    }

    public function test_human_urgency_low_for_completed_info(): void
    {
        $alert = $this->createAlert([
            'human_status' => Alert::HUMAN_STATUS_PENDING,
            'ai_status' => Alert::STATUS_COMPLETED,
            'severity' => Alert::SEVERITY_INFO,
            'risk_escalation' => Alert::RISK_MONITOR,
        ]);
        $this->assertEquals('low', $alert->getHumanUrgencyLevel());
    }

    // =========================================================================
    // SLA helpers
    // =========================================================================

    public function test_ack_sla_remaining_returns_null_when_no_due_date(): void
    {
        $alert = $this->createAlert(['ack_due_at' => null]);
        $this->assertNull($alert->ackSlaRemainingSeconds());
    }

    public function test_ack_sla_remaining_returns_null_when_already_acked(): void
    {
        $alert = $this->createAlert([
            'ack_status' => Alert::ACK_ACKED,
            'ack_due_at' => now()->addMinutes(10),
        ]);
        $this->assertNull($alert->ackSlaRemainingSeconds());
    }

    public function test_ack_sla_remaining_returns_positive_when_not_overdue(): void
    {
        $alert = $this->createAlert([
            'ack_status' => Alert::ACK_PENDING,
            'ack_due_at' => now()->addMinutes(10),
        ]);
        $remaining = $alert->ackSlaRemainingSeconds();
        $this->assertNotNull($remaining);
        $this->assertGreaterThan(0, $remaining);
    }

    public function test_ack_sla_remaining_returns_negative_when_overdue(): void
    {
        $alert = $this->createAlert([
            'ack_status' => Alert::ACK_PENDING,
            'ack_due_at' => now()->subMinutes(5),
        ]);
        $remaining = $alert->ackSlaRemainingSeconds();
        $this->assertNotNull($remaining);
        $this->assertLessThan(0, $remaining);
    }

    public function test_resolve_sla_remaining_returns_null_when_closed(): void
    {
        $alert = $this->createAlert([
            'attention_state' => Alert::ATTENTION_CLOSED,
            'resolve_due_at' => now()->addHours(4),
        ]);
        $this->assertNull($alert->resolveSlaRemainingSeconds());
    }

    public function test_resolve_sla_remaining_returns_null_when_no_due_date(): void
    {
        $alert = $this->createAlert(['resolve_due_at' => null]);
        $this->assertNull($alert->resolveSlaRemainingSeconds());
    }

    public function test_resolve_sla_remaining_returns_seconds_when_active(): void
    {
        $alert = $this->createAlert([
            'attention_state' => Alert::ATTENTION_NEEDS_ATTENTION,
            'resolve_due_at' => now()->addHours(2),
        ]);
        $remaining = $alert->resolveSlaRemainingSeconds();
        $this->assertNotNull($remaining);
        $this->assertGreaterThan(0, $remaining);
    }

    // =========================================================================
    // warrantsAttention edge cases
    // =========================================================================

    public function test_warrants_attention_returns_true_for_warn_escalation(): void
    {
        $alert = $this->createAlert([
            'severity' => Alert::SEVERITY_INFO,
            'risk_escalation' => Alert::RISK_WARN,
        ]);
        $this->assertTrue($alert->warrantsAttention());
    }

    public function test_warrants_attention_returns_true_for_high_risk_verdict(): void
    {
        $alert = $this->createAlert([
            'severity' => Alert::SEVERITY_INFO,
            'risk_escalation' => Alert::RISK_MONITOR,
            'verdict' => Alert::VERDICT_REAL_PANIC,
        ]);
        $this->assertTrue($alert->warrantsAttention());
    }

    public function test_warrants_attention_returns_false_for_low_risk(): void
    {
        $alert = $this->createAlert([
            'severity' => Alert::SEVERITY_INFO,
            'risk_escalation' => Alert::RISK_MONITOR,
            'verdict' => Alert::VERDICT_NO_ACTION_NEEDED,
        ]);
        $this->assertFalse($alert->warrantsAttention());
    }

    // =========================================================================
    // Label defaults
    // =========================================================================

    public function test_verdict_label_returns_default_when_null(): void
    {
        $alert = $this->createAlert(['verdict' => null]);
        $this->assertEquals('Sin veredicto', $alert->getVerdictLabel());
    }

    public function test_likelihood_label_returns_default_when_null(): void
    {
        $alert = $this->createAlert(['likelihood' => null]);
        $this->assertEquals('Sin evaluación', $alert->getLikelihoodLabel());
    }

    public function test_alert_kind_label_returns_default_when_null(): void
    {
        $alert = $this->createAlert(['alert_kind' => null]);
        $this->assertEquals('Sin clasificar', $alert->getAlertKindLabel());
    }

    // =========================================================================
    // Remaining scopes
    // =========================================================================

    public function test_scope_investigating_filters_investigating_alerts(): void
    {
        $this->createAlert(['ai_status' => Alert::STATUS_INVESTIGATING]);
        $this->createAlert(['ai_status' => Alert::STATUS_COMPLETED]);

        $result = Alert::forCompany($this->company->id)->investigating()->get();
        $this->assertCount(1, $result);
        $this->assertEquals(Alert::STATUS_INVESTIGATING, $result->first()->ai_status);
    }

    public function test_scope_processing_filters_processing_alerts(): void
    {
        $this->createAlert(['ai_status' => Alert::STATUS_PROCESSING]);
        $this->createAlert(['ai_status' => Alert::STATUS_PENDING]);

        $result = Alert::forCompany($this->company->id)->processing()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_overdue_ack_filters_overdue_pending_acks(): void
    {
        $this->createAlert([
            'ack_status' => Alert::ACK_PENDING,
            'ack_due_at' => now()->subMinute(),
        ]);
        $this->createAlert([
            'ack_status' => Alert::ACK_PENDING,
            'ack_due_at' => now()->addHour(),
        ]);
        $this->createAlert([
            'ack_status' => Alert::ACK_ACKED,
            'ack_due_at' => now()->subMinute(),
        ]);

        $result = Alert::forCompany($this->company->id)->overdueAck()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_unacked_filters_needs_attention_pending_acks(): void
    {
        $this->createAlert([
            'attention_state' => Alert::ATTENTION_NEEDS_ATTENTION,
            'ack_status' => Alert::ACK_PENDING,
        ]);
        $this->createAlert([
            'attention_state' => Alert::ATTENTION_NEEDS_ATTENTION,
            'ack_status' => Alert::ACK_ACKED,
        ]);

        $result = Alert::forCompany($this->company->id)->unacked()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_needs_escalation_filters_overdue_escalation(): void
    {
        $this->createAlert([
            'attention_state' => Alert::ATTENTION_NEEDS_ATTENTION,
            'ack_status' => Alert::ACK_PENDING,
            'next_escalation_at' => now()->subMinute(),
        ]);
        $this->createAlert([
            'attention_state' => Alert::ATTENTION_NEEDS_ATTENTION,
            'ack_status' => Alert::ACK_PENDING,
            'next_escalation_at' => now()->addHour(),
        ]);

        $result = Alert::forCompany($this->company->id)->needsEscalation()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_needs_human_attention_complex_filter(): void
    {
        $this->createAlert([
            'human_status' => Alert::HUMAN_STATUS_PENDING,
            'ai_status' => Alert::STATUS_FAILED,
            'severity' => Alert::SEVERITY_INFO,
        ]);
        $this->createAlert([
            'human_status' => Alert::HUMAN_STATUS_PENDING,
            'ai_status' => Alert::STATUS_COMPLETED,
            'severity' => Alert::SEVERITY_CRITICAL,
        ]);
        $this->createAlert([
            'human_status' => Alert::HUMAN_STATUS_PENDING,
            'ai_status' => Alert::STATUS_COMPLETED,
            'severity' => Alert::SEVERITY_INFO,
            'risk_escalation' => Alert::RISK_EMERGENCY,
        ]);
        $this->createAlert([
            'human_status' => Alert::HUMAN_STATUS_REVIEWED,
            'ai_status' => Alert::STATUS_FAILED,
        ]);
        $this->createAlert([
            'human_status' => Alert::HUMAN_STATUS_PENDING,
            'ai_status' => Alert::STATUS_COMPLETED,
            'severity' => Alert::SEVERITY_INFO,
            'risk_escalation' => Alert::RISK_MONITOR,
        ]);

        $result = Alert::forCompany($this->company->id)->needsHumanAttention()->get();
        $this->assertCount(3, $result);
    }

    public function test_scope_by_likelihood_filters_by_likelihood(): void
    {
        $this->createAlert(['likelihood' => Alert::LIKELIHOOD_HIGH]);
        $this->createAlert(['likelihood' => Alert::LIKELIHOOD_LOW]);

        $result = Alert::forCompany($this->company->id)->byLikelihood(Alert::LIKELIHOOD_HIGH)->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_human_flagged_filters_by_flagged_status(): void
    {
        $this->createAlert(['human_status' => Alert::HUMAN_STATUS_FLAGGED]);
        $this->createAlert(['human_status' => Alert::HUMAN_STATUS_PENDING]);

        $result = Alert::forCompany($this->company->id)->humanFlagged()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_human_resolved_filters_by_resolved_status(): void
    {
        $this->createAlert(['human_status' => Alert::HUMAN_STATUS_RESOLVED]);
        $this->createAlert(['human_status' => Alert::HUMAN_STATUS_PENDING]);

        $result = Alert::forCompany($this->company->id)->humanResolved()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_human_false_positive_filters_correctly(): void
    {
        $this->createAlert(['human_status' => Alert::HUMAN_STATUS_FALSE_POSITIVE]);
        $this->createAlert(['human_status' => Alert::HUMAN_STATUS_PENDING]);

        $result = Alert::forCompany($this->company->id)->humanFalsePositive()->get();
        $this->assertCount(1, $result);
    }

    public function test_scope_order_by_attention_priority_sorts_correctly(): void
    {
        $emergency = $this->createAlert([
            'risk_escalation' => Alert::RISK_EMERGENCY,
            'severity' => Alert::SEVERITY_INFO,
            'occurred_at' => now()->subHour(),
        ]);
        $info = $this->createAlert([
            'risk_escalation' => Alert::RISK_MONITOR,
            'severity' => Alert::SEVERITY_INFO,
            'occurred_at' => now(),
        ]);
        $critical = $this->createAlert([
            'risk_escalation' => Alert::RISK_MONITOR,
            'severity' => Alert::SEVERITY_CRITICAL,
            'occurred_at' => now()->subMinutes(30),
        ]);

        $result = Alert::forCompany($this->company->id)->orderByAttentionPriority()->get();
        $this->assertEquals($emergency->id, $result->first()->id);
    }

    // =========================================================================
    // isOverdueForResolution additional
    // =========================================================================

    public function test_is_overdue_for_resolution_returns_false_when_no_due_date(): void
    {
        $alert = $this->createAlert([
            'attention_state' => Alert::ATTENTION_NEEDS_ATTENTION,
            'resolve_due_at' => null,
        ]);
        $this->assertFalse($alert->isOverdueForResolution());
    }

    // =========================================================================
    // is_overdue_for_ack when null due date
    // =========================================================================

    public function test_is_overdue_for_ack_returns_false_when_null_due_date(): void
    {
        $alert = $this->createAlert([
            'ack_status' => Alert::ACK_PENDING,
            'ack_due_at' => null,
        ]);
        $this->assertFalse($alert->isOverdueForAck());
    }

    // =========================================================================
    // hasOwner with contact
    // =========================================================================

    public function test_has_owner_returns_true_when_owner_contact_set(): void
    {
        $contact = \App\Models\Contact::factory()->create(['company_id' => $this->company->id]);
        $alert = $this->createAlert(['owner_user_id' => null, 'owner_contact_id' => $contact->id]);
        $this->assertTrue($alert->hasOwner());
    }

    // =========================================================================
    // isProbableFalsePositive false case
    // =========================================================================

    public function test_is_probable_false_positive_returns_false_for_confirmed(): void
    {
        $alert = $this->createAlert([
            'verdict' => Alert::VERDICT_CONFIRMED_VIOLATION,
            'human_status' => Alert::HUMAN_STATUS_PENDING,
        ]);
        $this->assertFalse($alert->isProbableFalsePositive());
    }

    // =========================================================================
    // shouldRevalidate - time window met
    // =========================================================================

    public function test_should_revalidate_returns_true_when_next_check_minutes_is_zero(): void
    {
        $alert = $this->createAlert(['ai_status' => Alert::STATUS_INVESTIGATING]);
        AlertAi::factory()->create([
            'alert_id' => $alert->id,
            'last_investigation_at' => null,
            'next_check_minutes' => 0,
        ]);

        $this->assertTrue($alert->fresh()->shouldRevalidate());
    }

    // =========================================================================
    // Notification helpers
    // =========================================================================

    public function test_record_notification_result_creates_record(): void
    {
        $alert = $this->createAlert();

        $result = $alert->recordNotificationResult([
            'channel' => 'sms',
            'to_number' => '+15551234567',
            'status_current' => 'queued',
            'message_sid' => 'SM123',
            'success' => true,
            'timestamp_utc' => now(),
        ]);

        $this->assertDatabaseHas('notification_results', [
            'alert_id' => $alert->id,
            'channel' => 'sms',
            'message_sid' => 'SM123',
        ]);
    }
}
