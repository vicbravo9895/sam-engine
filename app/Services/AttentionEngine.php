<?php

namespace App\Services;

use App\Jobs\SendNotificationJob;
use App\Models\Alert;
use App\Models\AlertActivity;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

class AttentionEngine
{
    /**
     * Activate attention tracking for an alert after AI processing.
     *
     * Only alerts that warrant attention (critical severity, escalation level,
     * or high-risk verdict) are tracked. Others are silently skipped.
     */
    public function initializeAttention(Alert $alert): void
    {
        if (!$this->isEnabled($alert)) {
            return;
        }

        if ($alert->attention_state !== null) {
            return;
        }

        if (!$alert->warrantsAttention()) {
            return;
        }

        $company = $alert->company;
        if (!$company) {
            return;
        }

        $severityKey = $alert->severity ?? 'info';
        $sla = $company->getAiConfig("sla_policies.{$severityKey}", [
            'ack_minutes' => 60,
            'resolve_minutes' => 1440,
        ]);

        $escalationPolicy = $company->getAiConfig('escalation_policy', []);
        $intervalMinutes = $escalationPolicy['escalation_interval_minutes'] ?? 10;

        $now = now();
        $alert->update([
            'attention_state' => Alert::ATTENTION_NEEDS_ATTENTION,
            'ack_status' => Alert::ACK_PENDING,
            'ack_due_at' => $now->copy()->addMinutes($sla['ack_minutes']),
            'resolve_due_at' => $now->copy()->addMinutes($sla['resolve_minutes']),
            'next_escalation_at' => $now->copy()->addMinutes($intervalMinutes),
            'escalation_level' => 0,
            'escalation_count' => 0,
        ]);

        DomainEventEmitter::emit(
            companyId: $alert->company_id,
            entityType: 'alert',
            entityId: (string) $alert->id,
            eventType: 'alert.attention_initialized',
            payload: [
                'severity' => $alert->severity,
                'risk_escalation' => $alert->risk_escalation,
                'ack_due_at' => $alert->ack_due_at?->toIso8601String(),
                'resolve_due_at' => $alert->resolve_due_at?->toIso8601String(),
            ],
            correlationId: (string) $alert->id,
        );

        Log::info('AttentionEngine: initialized', [
            'alert_id' => $alert->id,
            'severity' => $alert->severity,
            'ack_due_at' => $alert->ack_due_at?->toIso8601String(),
            'resolve_due_at' => $alert->resolve_due_at?->toIso8601String(),
        ]);
    }

    /**
     * Acknowledge an alert, stopping the escalation timer.
     */
    public function acknowledge(Alert $alert, User $user): void
    {
        if ($alert->ack_status === Alert::ACK_ACKED) {
            return;
        }

        $alert->update([
            'ack_status' => Alert::ACK_ACKED,
            'acked_at' => now(),
            'attention_state' => Alert::ATTENTION_IN_PROGRESS,
            'next_escalation_at' => null,
        ]);

        DomainEventEmitter::emit(
            companyId: $alert->company_id,
            entityType: 'alert',
            entityId: (string) $alert->id,
            eventType: 'alert.acked',
            payload: [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'time_to_ack_seconds' => $alert->ack_due_at
                    ? (int) $alert->acked_at->diffInSeconds($alert->ack_due_at, false)
                    : null,
            ],
            actorType: 'user',
            actorId: (string) $user->id,
            correlationId: (string) $alert->id,
        );

        try {
            AlertActivity::logHumanAction(
                $alert->id,
                $alert->company_id,
                $user->id,
                'attention_acked',
                ['user_name' => $user->name]
            );
        } catch (\Exception $e) {
            Log::warning('AttentionEngine: failed to log ack activity', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Assign an owner (user or contact) to the alert.
     */
    public function assignOwner(
        Alert $alert,
        ?int $userId,
        ?int $contactId,
        User $assignedBy
    ): void {
        $previousOwnerUserId = $alert->owner_user_id;
        $previousOwnerContactId = $alert->owner_contact_id;

        $alert->update([
            'owner_user_id' => $userId,
            'owner_contact_id' => $contactId,
        ]);

        DomainEventEmitter::emit(
            companyId: $alert->company_id,
            entityType: 'alert',
            entityId: (string) $alert->id,
            eventType: 'alert.assigned',
            payload: [
                'owner_user_id' => $userId,
                'owner_contact_id' => $contactId,
                'previous_owner_user_id' => $previousOwnerUserId,
                'previous_owner_contact_id' => $previousOwnerContactId,
                'assigned_by' => $assignedBy->id,
            ],
            actorType: 'user',
            actorId: (string) $assignedBy->id,
            correlationId: (string) $alert->id,
        );

        try {
            AlertActivity::logHumanAction(
                $alert->id,
                $alert->company_id,
                $assignedBy->id,
                'attention_assigned',
                [
                    'owner_user_id' => $userId,
                    'owner_contact_id' => $contactId,
                    'assigned_by_name' => $assignedBy->name,
                ]
            );
        } catch (\Exception $e) {
            Log::warning('AttentionEngine: failed to log assign activity', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Escalate an overdue alert to the next notification level.
     */
    public function escalate(Alert $alert): void
    {
        $company = $alert->company;
        if (!$company) {
            return;
        }

        $escalationPolicy = $company->getAiConfig('escalation_policy', []);
        $maxEscalations = $escalationPolicy['max_escalations'] ?? 3;
        $intervalMinutes = $escalationPolicy['escalation_interval_minutes'] ?? 10;

        if ($alert->escalation_count >= $maxEscalations) {
            Log::info('AttentionEngine: max escalations reached', [
                'alert_id' => $alert->id,
                'escalation_count' => $alert->escalation_count,
                'max_escalations' => $maxEscalations,
            ]);
            $alert->update(['next_escalation_at' => null]);
            return;
        }

        $newLevel = min($alert->escalation_level + 1, 2);
        $newCount = $alert->escalation_count + 1;

        $alert->update([
            'escalation_level' => $newLevel,
            'escalation_count' => $newCount,
            'next_escalation_at' => now()->addMinutes($intervalMinutes),
        ]);

        $matrixKey = $alert->getEscalationMatrixKey();
        $matrix = $company->getAiConfig("escalation_matrix.{$matrixKey}", [
            'channels' => ['whatsapp'],
            'recipients' => ['monitoring'],
        ]);

        $channels = $matrix['channels'] ?? ['whatsapp'];
        $recipientTypes = $matrix['recipients'] ?? ['monitoring'];

        $signal = $alert->signal;
        $contactResolver = app(ContactResolver::class);
        $resolvedContacts = $contactResolver->resolve(
            $signal?->vehicle_id,
            $signal?->driver_id,
            $alert->company_id
        );

        $recipients = [];
        foreach ($recipientTypes as $type) {
            $typeKey = $type === 'monitoring' ? 'monitoring_team' : $type;
            $contactData = $resolvedContacts[$typeKey] ?? null;
            if ($contactData && (($contactData['phone'] ?? null) || ($contactData['whatsapp'] ?? null))) {
                $recipients[] = array_merge($contactData, ['recipient_type' => $typeKey]);
            }
        }

        if (empty($recipients)) {
            foreach ($resolvedContacts as $type => $contactData) {
                if ($contactData && (($contactData['phone'] ?? null) || ($contactData['whatsapp'] ?? null))) {
                    $recipients[] = array_merge($contactData, ['recipient_type' => $type]);
                }
            }
        }

        if (empty($recipients)) {
            Log::warning('AttentionEngine: No contacts resolved for escalation', [
                'alert_id' => $alert->id,
                'recipient_types' => $recipientTypes,
            ]);
            return;
        }

        $escalationLevel = match ($matrixKey) {
            'emergency' => 'critical',
            'call' => 'high',
            'warn' => 'low',
            'monitor' => 'none',
            default => 'high',
        };

        $messageText = $alert->ai_message ?? 'Alerta requiere atención — escalación automática';
        $callScript = mb_substr($messageText, 0, 200);

        $escalationDecision = [
            'should_notify' => true,
            'escalation_level' => $escalationLevel,
            'channels_to_use' => $channels,
            'recipients' => $recipients,
            'message_text' => $messageText,
            'call_script' => $callScript,
            'dedupe_key' => "escalation-{$alert->id}-{$newCount}",
            'reason' => "Escalación automática (nivel {$newCount}/{$maxEscalations}): sin ACK en tiempo SLA",
            'is_escalation' => true,
        ];

        SendNotificationJob::dispatch($alert, $escalationDecision);

        DomainEventEmitter::emit(
            companyId: $alert->company_id,
            entityType: 'alert',
            entityId: (string) $alert->id,
            eventType: 'alert.escalated',
            payload: [
                'escalation_level' => $newLevel,
                'escalation_count' => $newCount,
                'matrix_key' => $matrixKey,
                'channels' => $matrix['channels'] ?? [],
                'max_escalations' => $maxEscalations,
            ],
            correlationId: (string) $alert->id,
        );

        try {
            AlertActivity::logAiAction(
                $alert->id,
                $alert->company_id,
                'attention_escalated',
                [
                    'escalation_level' => $newLevel,
                    'escalation_count' => $newCount,
                    'matrix_key' => $matrixKey,
                ]
            );
        } catch (\Exception $e) {
            Log::warning('AttentionEngine: failed to log escalation activity', ['error' => $e->getMessage()]);
        }

        Log::info('AttentionEngine: escalated', [
            'alert_id' => $alert->id,
            'escalation_level' => $newLevel,
            'escalation_count' => $newCount,
            'matrix_key' => $matrixKey,
        ]);
    }

    /**
     * Close the attention lifecycle for an alert.
     */
    public function closeAttention(Alert $alert, User $user, string $reason): void
    {
        if ($alert->attention_state === Alert::ATTENTION_CLOSED) {
            return;
        }

        $alert->update([
            'attention_state' => Alert::ATTENTION_CLOSED,
            'resolved_at' => now(),
            'next_escalation_at' => null,
        ]);

        DomainEventEmitter::emit(
            companyId: $alert->company_id,
            entityType: 'alert',
            entityId: (string) $alert->id,
            eventType: 'alert.attention_closed',
            payload: [
                'reason' => $reason,
                'closed_by' => $user->id,
                'time_to_resolve_seconds' => $alert->created_at
                    ? (int) now()->diffInSeconds($alert->created_at)
                    : null,
            ],
            actorType: 'user',
            actorId: (string) $user->id,
            correlationId: (string) $alert->id,
        );

        try {
            AlertActivity::logHumanAction(
                $alert->id,
                $alert->company_id,
                $user->id,
                'attention_closed',
                [
                    'reason' => $reason,
                    'user_name' => $user->name,
                ]
            );
        } catch (\Exception $e) {
            Log::warning('AttentionEngine: failed to log close activity', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Bulk-check for overdue alerts and escalate them.
     *
     * Called by CheckAttentionSlaJob on the scheduler.
     */
    public function checkAndEscalateOverdue(?int $companyId = null): int
    {
        $query = Alert::needsEscalation();

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Alert> $alerts */
        $alerts = $query->with('company')->limit(100)->get();

        $escalated = 0;
        /** @var Alert $alert */
        foreach ($alerts as $alert) {
            if (!$alert->company) {
                continue;
            }

            if (!$this->isEnabled($alert)) {
                continue;
            }

            try {
                $this->escalate($alert);
                $escalated++;
            } catch (\Exception $e) {
                Log::error('AttentionEngine: escalation failed', [
                    'alert_id' => $alert->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($escalated > 0) {
            Log::info('AttentionEngine: bulk escalation complete', [
                'escalated' => $escalated,
                'checked' => $alerts->count(),
            ]);
        }

        return $escalated;
    }

    private function isEnabled(Alert $alert): bool
    {
        $company = $alert->company ?? Company::find($alert->company_id);

        if (!$company) {
            return false;
        }

        return Feature::for($company)->active('attention-engine-v1');
    }
}
