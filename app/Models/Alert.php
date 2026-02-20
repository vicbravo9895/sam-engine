<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Alert extends Model
{
    use HasFactory;
    protected $fillable = [
        'company_id',
        'signal_id',
        'ai_status',
        'severity',
        'verdict',
        'likelihood',
        'confidence',
        'reasoning',
        'ai_message',
        'alert_kind',
        'dedupe_key',
        'risk_escalation',
        'proactive_flag',
        'human_status',
        'reviewed_by_id',
        'reviewed_at',
        'attention_state',
        'ack_status',
        'owner_user_id',
        'owner_contact_id',
        'ack_due_at',
        'acked_at',
        'resolve_due_at',
        'resolved_at',
        'next_escalation_at',
        'escalation_level',
        'escalation_count',
        'occurred_at',
        'notification_status',
        'notification_channels',
        'notification_sent_at',
        'twilio_call_sid',
        'call_response',
        'notification_decision_payload',
        'notification_execution',
        'event_description',
    ];

    protected $appends = ['needs_attention'];

    protected $casts = [
        'confidence' => 'decimal:2',
        'proactive_flag' => 'boolean',
        'occurred_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'ack_due_at' => 'datetime',
        'acked_at' => 'datetime',
        'resolve_due_at' => 'datetime',
        'resolved_at' => 'datetime',
        'next_escalation_at' => 'datetime',
        'escalation_level' => 'integer',
        'escalation_count' => 'integer',
        'notification_channels' => 'array',
        'notification_sent_at' => 'datetime',
        'call_response' => 'array',
        'notification_decision_payload' => 'array',
        'notification_execution' => 'array',
    ];

    // =========================================================================
    // Constants — AI Status
    // =========================================================================

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_INVESTIGATING = 'investigating';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    // =========================================================================
    // Constants — Severity
    // =========================================================================

    const SEVERITY_CRITICAL = 'critical';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_INFO = 'info';

    // =========================================================================
    // Constants — Risk Escalation
    // =========================================================================

    const RISK_MONITOR = 'monitor';
    const RISK_WARN = 'warn';
    const RISK_CALL = 'call';
    const RISK_EMERGENCY = 'emergency';

    // =========================================================================
    // Constants — Attention Engine
    // =========================================================================

    const ATTENTION_NEEDS_ATTENTION = 'needs_attention';
    const ATTENTION_IN_PROGRESS = 'in_progress';
    const ATTENTION_BLOCKED = 'blocked';
    const ATTENTION_CLOSED = 'closed';

    const ACK_PENDING = 'pending';
    const ACK_ACKED = 'acked';

    // =========================================================================
    // Constants — Human Status
    // =========================================================================

    const HUMAN_STATUS_PENDING = 'pending';
    const HUMAN_STATUS_REVIEWED = 'reviewed';
    const HUMAN_STATUS_FLAGGED = 'flagged';
    const HUMAN_STATUS_RESOLVED = 'resolved';
    const HUMAN_STATUS_FALSE_POSITIVE = 'false_positive';

    // =========================================================================
    // Constants — Verdict
    // =========================================================================

    const VERDICT_REAL_PANIC = 'real_panic';
    const VERDICT_CONFIRMED_VIOLATION = 'confirmed_violation';
    const VERDICT_NEEDS_REVIEW = 'needs_review';
    const VERDICT_UNCERTAIN = 'uncertain';
    const VERDICT_LIKELY_FALSE_POSITIVE = 'likely_false_positive';
    const VERDICT_NO_ACTION_NEEDED = 'no_action_needed';
    const VERDICT_RISK_DETECTED = 'risk_detected';

    // =========================================================================
    // Constants — Likelihood
    // =========================================================================

    const LIKELIHOOD_HIGH = 'high';
    const LIKELIHOOD_MEDIUM = 'medium';
    const LIKELIHOOD_LOW = 'low';

    // =========================================================================
    // Constants — Alert Kind
    // =========================================================================

    const ALERT_KIND_PANIC = 'panic';
    const ALERT_KIND_SAFETY = 'safety';
    const ALERT_KIND_TAMPERING = 'tampering';
    const ALERT_KIND_CONNECTIVITY = 'connectivity';
    const ALERT_KIND_UNKNOWN = 'unknown';

    // =========================================================================
    // Relationships
    // =========================================================================

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function ai(): HasOne
    {
        return $this->hasOne(AlertAi::class);
    }

    public function metrics(): HasOne
    {
        return $this->hasOne(AlertMetrics::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(AlertSource::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function ownerContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'owner_contact_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(AlertComment::class)->orderBy('created_at', 'desc');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(AlertActivity::class)->orderBy('created_at', 'desc');
    }

    public function notificationResults(): HasMany
    {
        return $this->hasMany(NotificationResult::class, 'alert_id')
            ->orderBy('timestamp_utc');
    }

    public function notificationAcks(): HasMany
    {
        return $this->hasMany(NotificationAck::class, 'alert_id');
    }

    public function notificationDecisions(): HasMany
    {
        return $this->hasMany(NotificationDecision::class, 'alert_id');
    }

    public function recommendedActions(): HasMany
    {
        return $this->hasMany(EventRecommendedAction::class, 'alert_id')
            ->orderBy('display_order');
    }

    public function investigationSteps(): HasMany
    {
        return $this->hasMany(EventInvestigationStep::class, 'alert_id')
            ->orderBy('step_order');
    }

    // =========================================================================
    // Scopes — AI Status
    // =========================================================================

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('alerts.company_id', $companyId);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('ai_status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('ai_status', self::STATUS_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->where('ai_status', self::STATUS_PROCESSING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('ai_status', self::STATUS_COMPLETED);
    }

    public function scopeInvestigating($query)
    {
        return $query->where('ai_status', self::STATUS_INVESTIGATING);
    }

    public function scopeFailed($query)
    {
        return $query->where('ai_status', self::STATUS_FAILED);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    public function scopeProactive($query)
    {
        return $query->where('proactive_flag', true);
    }

    public function scopeByRiskEscalation($query, string $level)
    {
        return $query->where('risk_escalation', $level);
    }

    public function scopeByDedupeKey($query, string $key)
    {
        return $query->where('dedupe_key', $key);
    }

    // =========================================================================
    // Scopes — Human Status
    // =========================================================================

    public function scopeHumanPending($query)
    {
        return $query->where('human_status', self::HUMAN_STATUS_PENDING);
    }

    public function scopeHumanReviewed($query)
    {
        return $query->where('human_status', self::HUMAN_STATUS_REVIEWED);
    }

    public function scopeHumanFlagged($query)
    {
        return $query->where('human_status', self::HUMAN_STATUS_FLAGGED);
    }

    public function scopeHumanResolved($query)
    {
        return $query->where('human_status', self::HUMAN_STATUS_RESOLVED);
    }

    public function scopeHumanFalsePositive($query)
    {
        return $query->where('human_status', self::HUMAN_STATUS_FALSE_POSITIVE);
    }

    // =========================================================================
    // Scopes — Normalized Fields
    // =========================================================================

    public function scopeByVerdict($query, string $verdict)
    {
        return $query->where('verdict', $verdict);
    }

    public function scopeByLikelihood($query, string $likelihood)
    {
        return $query->where('likelihood', $likelihood);
    }

    public function scopeByAlertKind($query, string $alertKind)
    {
        return $query->where('alert_kind', $alertKind);
    }

    public function scopeHighConfidence($query, float $threshold = 0.8)
    {
        return $query->where('confidence', '>=', $threshold);
    }

    public function scopePanicAlerts($query)
    {
        return $query->where('alert_kind', self::ALERT_KIND_PANIC);
    }

    public function scopeSafetyAlerts($query)
    {
        return $query->where('alert_kind', self::ALERT_KIND_SAFETY);
    }

    public function scopeNeedsAttention($query)
    {
        return $query->whereNotNull('attention_state')
                     ->where('attention_state', '!=', self::ATTENTION_CLOSED);
    }

    public function scopeNeedsHumanAttention($query)
    {
        return $query->where('human_status', self::HUMAN_STATUS_PENDING)
            ->where(function ($q) {
                $q->whereIn('ai_status', [self::STATUS_FAILED, self::STATUS_INVESTIGATING])
                  ->orWhere('severity', self::SEVERITY_CRITICAL)
                  ->orWhereIn('risk_escalation', [self::RISK_CALL, self::RISK_EMERGENCY]);
            });
    }

    // =========================================================================
    // Scopes — Attention Engine
    // =========================================================================

    public function scopeOverdueAck($query)
    {
        return $query->where('ack_status', self::ACK_PENDING)
                     ->whereNotNull('ack_due_at')
                     ->where('ack_due_at', '<', now());
    }

    public function scopeUnacked($query)
    {
        return $query->where('attention_state', self::ATTENTION_NEEDS_ATTENTION)
                     ->where('ack_status', self::ACK_PENDING);
    }

    public function scopeNeedsEscalation($query)
    {
        return $query->where('attention_state', self::ATTENTION_NEEDS_ATTENTION)
                     ->where('ack_status', self::ACK_PENDING)
                     ->whereNotNull('next_escalation_at')
                     ->where('next_escalation_at', '<=', now());
    }

    public function scopeByAttentionState($query, string $state)
    {
        return $query->where('attention_state', $state);
    }

    public function scopeOrderByAttentionPriority($query)
    {
        return $query->orderByRaw(
            "CASE risk_escalation WHEN 'emergency' THEN 4 WHEN 'call' THEN 3 WHEN 'warn' THEN 2 WHEN 'monitor' THEN 1 ELSE 0 END DESC"
        )->orderByRaw(
            "CASE severity WHEN 'critical' THEN 3 WHEN 'warning' THEN 2 WHEN 'info' THEN 1 ELSE 0 END DESC"
        )->orderByRaw(
            "CASE WHEN human_status = 'pending' THEN 1 ELSE 0 END DESC"
        )->orderByRaw(
            "CASE ai_status WHEN 'failed' THEN 3 WHEN 'investigating' THEN 2 WHEN 'processing' THEN 1 WHEN 'pending' THEN 0 ELSE -1 END DESC"
        )->orderByDesc('occurred_at')
         ->orderByDesc('created_at');
    }

    // =========================================================================
    // State Transitions — AI Status
    // =========================================================================

    public function markAsProcessing(): void
    {
        $this->update(['ai_status' => self::STATUS_PROCESSING]);
    }

    public function markAsCompleted(
        array $assessment,
        string $humanMessage,
        ?array $alertContext = null,
        ?array $notificationDecision = null,
        ?array $notificationExecution = null,
        ?array $execution = null
    ): void {
        $data = [
            'ai_status' => self::STATUS_COMPLETED,
            'ai_message' => $humanMessage,
        ];

        $this->applyAssessmentFields($data, $assessment, $alertContext);

        if ($notificationDecision !== null) {
            $data['notification_decision_payload'] = $notificationDecision;
        }

        $this->update($data);

        $this->syncAiData($assessment, $alertContext, $execution);
    }

    public function markAsInvestigating(
        array $assessment,
        string $humanMessage,
        int $nextCheckMinutes,
        ?array $alertContext = null,
        ?array $notificationDecision = null,
        ?array $notificationExecution = null,
        ?array $execution = null
    ): void {
        $data = [
            'ai_status' => self::STATUS_INVESTIGATING,
            'ai_message' => $humanMessage,
        ];

        $this->applyAssessmentFields($data, $assessment, $alertContext);

        if ($notificationDecision !== null) {
            $data['notification_decision_payload'] = $notificationDecision;
        }

        $this->update($data);

        $ai = $this->ai;
        if ($ai) {
            $ai->update([
                'last_investigation_at' => now(),
                'investigation_count' => ($ai->investigation_count ?? 0) + 1,
                'next_check_minutes' => $nextCheckMinutes,
            ]);
        }

        $this->syncAiData($assessment, $alertContext, $execution);
    }

    public function markAsFailed(string $error): void
    {
        $this->update(['ai_status' => self::STATUS_FAILED]);

        $ai = $this->ai;
        if ($ai) {
            $ai->update(['ai_error' => $error]);
        } else {
            AlertAi::create([
                'alert_id' => $this->id,
                'ai_error' => $error,
            ]);
        }
    }

    private function applyAssessmentFields(array &$data, array $assessment, ?array $alertContext): void
    {
        $data['verdict'] = $assessment['verdict'] ?? $this->verdict;
        $data['likelihood'] = $assessment['likelihood'] ?? $this->likelihood;
        $data['confidence'] = $assessment['confidence'] ?? $this->confidence;
        $data['reasoning'] = $assessment['reasoning'] ?? $this->reasoning;

        if (isset($assessment['dedupe_key'])) {
            $data['dedupe_key'] = $assessment['dedupe_key'];
        }
        if (isset($assessment['risk_escalation'])) {
            $data['risk_escalation'] = $assessment['risk_escalation'];
        }
        if ($alertContext !== null) {
            $data['proactive_flag'] = $alertContext['proactive_flag'] ?? false;
            $data['alert_kind'] = $alertContext['alert_kind'] ?? $this->alert_kind;
        }
    }

    private function syncAiData(array $assessment, ?array $alertContext, ?array $execution): void
    {
        $aiData = array_filter([
            'alert_context' => $alertContext,
            'ai_assessment' => $assessment,
            'ai_actions' => $execution,
            'monitoring_reason' => $assessment['monitoring_reason'] ?? null,
            'triage_notes' => $alertContext['triage_notes'] ?? null,
            'investigation_strategy' => $alertContext['investigation_strategy'] ?? null,
            'supporting_evidence' => $assessment['supporting_evidence'] ?? null,
        ], fn ($v) => $v !== null);

        if (!empty($aiData)) {
            AlertAi::updateOrCreate(
                ['alert_id' => $this->id],
                $aiData
            );
        }
    }

    // =========================================================================
    // Helper Methods — Investigation
    // =========================================================================

    public function addInvestigationRecord(string $reason): void
    {
        $ai = $this->ai;
        if (!$ai) {
            return;
        }

        $history = $ai->investigation_history ?? [];

        $lastIndex = count($history) - 1;
        if ($lastIndex >= 0 && isset($history[$lastIndex]['investigation_number'])) {
            $history[$lastIndex]['ai_reason'] = $reason;
            $history[$lastIndex]['ai_evaluated_at'] = now()->toIso8601String();
        } else {
            $history[] = [
                'timestamp' => now()->toIso8601String(),
                'reason' => $reason,
                'count' => $ai->investigation_count,
            ];
        }

        $ai->update(['investigation_history' => $history]);
    }

    public function saveRecommendedActions(array $actions): void
    {
        EventRecommendedAction::replaceForAlert($this->id, $actions);
    }

    public function saveInvestigationSteps(array $steps): void
    {
        EventInvestigationStep::replaceForAlert($this->id, $steps);
    }

    public function getRecommendedActionsArray(): array
    {
        $actions = $this->recommendedActions()->pluck('action_text')->toArray();
        return !empty($actions) ? $actions : [];
    }

    public function getInvestigationStepsArray(): array
    {
        $steps = $this->investigationSteps()->pluck('step_text')->toArray();
        return !empty($steps) ? $steps : [];
    }

    public static function getMaxInvestigations(?Company $company = null): int
    {
        if ($company) {
            return (int) $company->getAiConfig('usage_limits.max_revalidations_per_event', 3);
        }
        return 3;
    }

    // =========================================================================
    // Helper Methods — Human Status
    // =========================================================================

    public function setHumanStatus(string $status, int $userId): void
    {
        $validStatuses = [
            self::HUMAN_STATUS_PENDING,
            self::HUMAN_STATUS_REVIEWED,
            self::HUMAN_STATUS_FLAGGED,
            self::HUMAN_STATUS_RESOLVED,
            self::HUMAN_STATUS_FALSE_POSITIVE,
        ];

        if (!in_array($status, $validStatuses)) {
            throw new \InvalidArgumentException("Invalid human_status: {$status}");
        }

        $oldStatus = $this->human_status;

        $this->update([
            'human_status' => $status,
            'reviewed_by_id' => $userId,
            'reviewed_at' => now(),
        ]);

        AlertActivity::logHumanAction(
            $this->id,
            $this->company_id,
            $userId,
            AlertActivity::ACTION_HUMAN_STATUS_CHANGED,
            ['old_status' => $oldStatus, 'new_status' => $status]
        );
    }

    public function addComment(int $userId, string $content): AlertComment
    {
        $comment = $this->comments()->create([
            'user_id' => $userId,
            'content' => $content,
            'company_id' => $this->company_id,
        ]);

        AlertActivity::logHumanAction(
            $this->id,
            $this->company_id,
            $userId,
            AlertActivity::ACTION_COMMENT_ADDED,
            ['comment_id' => $comment->id]
        );

        return $comment;
    }

    public function isHumanReviewed(): bool
    {
        return $this->human_status !== self::HUMAN_STATUS_PENDING;
    }

    // =========================================================================
    // Computed Attributes
    // =========================================================================

    public function getNeedsAttentionAttribute(): bool
    {
        if ($this->attention_state === self::ATTENTION_CLOSED) {
            return false;
        }

        if ($this->attention_state === self::ATTENTION_NEEDS_ATTENTION) {
            return true;
        }

        if ($this->human_status !== self::HUMAN_STATUS_PENDING) {
            return false;
        }

        return $this->ai_status === self::STATUS_FAILED
            || $this->ai_status === self::STATUS_INVESTIGATING
            || $this->severity === self::SEVERITY_CRITICAL
            || $this->requiresUrgentEscalation();
    }

    // =========================================================================
    // Attention Engine Helpers
    // =========================================================================

    public function warrantsAttention(): bool
    {
        if ($this->severity === self::SEVERITY_CRITICAL) {
            return true;
        }

        if (in_array($this->risk_escalation, [self::RISK_WARN, self::RISK_CALL, self::RISK_EMERGENCY])) {
            return true;
        }

        if (in_array($this->verdict, [
            self::VERDICT_REAL_PANIC,
            self::VERDICT_CONFIRMED_VIOLATION,
            self::VERDICT_RISK_DETECTED,
            self::VERDICT_NEEDS_REVIEW,
        ])) {
            return true;
        }

        return false;
    }

    public function getHumanUrgencyLevel(): string
    {
        if ($this->human_status !== self::HUMAN_STATUS_PENDING) {
            return 'low';
        }

        if ($this->ai_status === self::STATUS_FAILED || $this->severity === self::SEVERITY_CRITICAL) {
            return 'high';
        }

        if ($this->requiresUrgentEscalation()) {
            return 'high';
        }

        $ai = $this->ai;
        if ($this->ai_status === self::STATUS_INVESTIGATING && $ai && $ai->investigation_count >= 2) {
            return 'medium';
        }

        if ($this->ai_status === self::STATUS_INVESTIGATING) {
            return 'medium';
        }

        return 'low';
    }

    public function requiresUrgentEscalation(): bool
    {
        return in_array($this->risk_escalation, [self::RISK_CALL, self::RISK_EMERGENCY]);
    }

    public function hasOwner(): bool
    {
        return $this->owner_user_id !== null || $this->owner_contact_id !== null;
    }

    public function isOverdueForAck(): bool
    {
        return $this->ack_status === self::ACK_PENDING
            && $this->ack_due_at !== null
            && now()->greaterThan($this->ack_due_at);
    }

    public function isOverdueForResolution(): bool
    {
        return $this->attention_state !== self::ATTENTION_CLOSED
            && $this->resolve_due_at !== null
            && now()->greaterThan($this->resolve_due_at);
    }

    public function ackSlaRemainingSeconds(): ?int
    {
        if ($this->ack_due_at === null || $this->ack_status === self::ACK_ACKED) {
            return null;
        }

        return (int) now()->diffInSeconds($this->ack_due_at, false);
    }

    public function resolveSlaRemainingSeconds(): ?int
    {
        if ($this->resolve_due_at === null || $this->attention_state === self::ATTENTION_CLOSED) {
            return null;
        }

        return (int) now()->diffInSeconds($this->resolve_due_at, false);
    }

    public function hasHighRiskVerdict(): bool
    {
        return in_array($this->verdict, [
            self::VERDICT_REAL_PANIC,
            self::VERDICT_CONFIRMED_VIOLATION,
            self::VERDICT_RISK_DETECTED,
        ]);
    }

    public function isCritical(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL;
    }

    public function isProcessed(): bool
    {
        return in_array($this->ai_status, [self::STATUS_COMPLETED, self::STATUS_FAILED]);
    }

    public function isProactive(): bool
    {
        return $this->proactive_flag === true;
    }

    public function isProbableFalsePositive(): bool
    {
        return $this->verdict === self::VERDICT_LIKELY_FALSE_POSITIVE
            || $this->human_status === self::HUMAN_STATUS_FALSE_POSITIVE;
    }

    public function shouldRevalidate(): bool
    {
        if ($this->ai_status !== self::STATUS_INVESTIGATING) {
            return false;
        }

        $ai = $this->ai;
        if (!$ai || !$ai->last_investigation_at || !$ai->next_check_minutes) {
            return true;
        }

        return now()->diffInMinutes($ai->last_investigation_at) >= $ai->next_check_minutes;
    }

    public function getEscalationMatrixKey(): string
    {
        return match (true) {
            $this->escalation_level >= 2 => self::RISK_EMERGENCY,
            $this->escalation_level >= 1 => self::RISK_CALL,
            default => self::RISK_WARN,
        };
    }

    // =========================================================================
    // Label Helpers
    // =========================================================================

    public function getVerdictLabel(): string
    {
        return match ($this->verdict) {
            self::VERDICT_REAL_PANIC => 'Pánico real',
            self::VERDICT_CONFIRMED_VIOLATION => 'Violación confirmada',
            self::VERDICT_NEEDS_REVIEW => 'Requiere revisión',
            self::VERDICT_UNCERTAIN => 'Incierto',
            self::VERDICT_LIKELY_FALSE_POSITIVE => 'Probable falso positivo',
            self::VERDICT_NO_ACTION_NEEDED => 'No requiere acción',
            self::VERDICT_RISK_DETECTED => 'Riesgo detectado',
            default => $this->verdict ?? 'Sin veredicto',
        };
    }

    public function getLikelihoodLabel(): string
    {
        return match ($this->likelihood) {
            self::LIKELIHOOD_HIGH => 'Alta',
            self::LIKELIHOOD_MEDIUM => 'Media',
            self::LIKELIHOOD_LOW => 'Baja',
            default => $this->likelihood ?? 'Sin evaluación',
        };
    }

    public function getAlertKindLabel(): string
    {
        return match ($this->alert_kind) {
            self::ALERT_KIND_PANIC => 'Pánico',
            self::ALERT_KIND_SAFETY => 'Seguridad',
            self::ALERT_KIND_TAMPERING => 'Manipulación',
            self::ALERT_KIND_CONNECTIVITY => 'Conectividad',
            self::ALERT_KIND_UNKNOWN => 'Desconocido',
            default => $this->alert_kind ?? 'Sin clasificar',
        };
    }

    // =========================================================================
    // Notification Helpers
    // =========================================================================

    public function saveNotificationDecision(array $decisionData, array $recipients = []): NotificationDecision
    {
        $this->notificationDecisions()->delete();

        $decisionData['alert_id'] = $this->id;
        return NotificationDecision::createWithRecipients($decisionData, $recipients);
    }

    public function recordNotificationResult(array $resultData): NotificationResult
    {
        $resultData['alert_id'] = $this->id;
        return NotificationResult::create($resultData);
    }
}
