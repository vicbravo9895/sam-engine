<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Modelo para eventos de Samsara.
 * 
 * ACTUALIZADO v2: Estructura normalizada relacional.
 * 
 * CAMPOS NORMALIZADOS (antes en JSON):
 * - verdict, likelihood, confidence, reasoning, monitoring_reason (de ai_assessment)
 * - alert_kind, triage_notes, investigation_strategy (de alert_context)
 * - time window configuration columns
 * - supporting_evidence (JSONB validado - única estructura variable)
 * 
 * TABLAS RELACIONADAS:
 * - event_recommended_actions: Acciones recomendadas
 * - event_investigation_steps: Pasos de investigación
 * - notification_decisions: Decisiones de notificación
 * - notification_results: Resultados de notificaciones
 * 
 * CORRELACIÓN:
 * - alert_incidents: Incidentes que agrupan alertas relacionadas
 * - alert_correlations: Correlaciones entre alertas
 * 
 * HUMAN REVIEW: Sistema de revisión humana independiente del AI.
 */
class SamsaraEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        // Company association
        'company_id',
        
        // Información del evento de Samsara
        'event_type',
        'event_description',
        'samsara_event_id',
        'vehicle_id',
        'vehicle_name',
        'driver_id',
        'driver_name',
        'severity',
        'occurred_at',
        'raw_payload',
        
        // Estado del procesamiento de IA
        'ai_status',
        'ai_assessment',  // Legacy - mantener por compatibilidad
        'ai_message',
        'ai_processed_at',
        'ai_error',
        'ai_actions',
        
        // =====================================================
        // CAMPOS NORMALIZADOS (antes en ai_assessment JSON)
        // =====================================================
        'verdict',
        'likelihood',
        'confidence',
        'reasoning',
        'monitoring_reason',
        
        // =====================================================
        // CAMPOS NORMALIZADOS (antes en alert_context JSON)
        // =====================================================
        'alert_kind',
        'triage_notes',
        'investigation_strategy',
        
        // Time window configuration
        'correlation_window_minutes',
        'media_window_seconds',
        'safety_events_before_minutes',
        'safety_events_after_minutes',
        
        // Supporting evidence (JSONB validado)
        'supporting_evidence',
        
        // Raw AI output for audit
        'raw_ai_output',
        
        // =====================================================
        // CORRELACIÓN
        // =====================================================
        'incident_id',
        'is_primary_event',
        
        // Legacy JSON fields (mantener por compatibilidad)
        'alert_context',
        'notification_decision',
        'notification_execution',
        
        // Campos operativos estandarizados
        'dedupe_key',
        'risk_escalation',
        'proactive_flag',
        'data_consistency',
        'recommended_actions',  // Legacy - ahora en tabla
        
        // Campos para investigación continua
        'last_investigation_at',
        'investigation_count',
        'next_check_minutes',
        'investigation_history',
        
        // Notification tracking (legacy/compatibilidad)
        'notification_status',
        'notification_channels',
        'notification_sent_at',
        'twilio_call_sid',
        'call_response',
        
        // Human review (independiente del AI)
        'human_status',
        'reviewed_by_id',
        'reviewed_at',
    ];

    protected $casts = [
        // Payloads JSON
        'raw_payload' => 'array',
        'ai_assessment' => 'array',
        'ai_actions' => 'array',
        'investigation_history' => 'array',
        
        // Campos normalizados
        'confidence' => 'decimal:2',
        'is_primary_event' => 'boolean',
        
        // JSONB validado
        'supporting_evidence' => 'array',
        'raw_ai_output' => 'array',
        
        // Legacy JSON fields (mantener por compatibilidad)
        'alert_context' => 'array',
        'notification_decision' => 'array',
        'notification_execution' => 'array',
        'data_consistency' => 'array',
        'recommended_actions' => 'array',
        
        // Booleans
        'proactive_flag' => 'boolean',
        
        // Timestamps
        'occurred_at' => 'datetime',
        'ai_processed_at' => 'datetime',
        'last_investigation_at' => 'datetime',
        
        // Notification fields (legacy)
        'notification_channels' => 'array',
        'notification_sent_at' => 'datetime',
        'call_response' => 'array',
        
        // Human review
        'reviewed_at' => 'datetime',
    ];

    // Constantes de estado
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_INVESTIGATING = 'investigating';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    // Constantes de severidad
    const SEVERITY_INFO = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_CRITICAL = 'critical';
    
    // Constantes de risk_escalation
    const RISK_MONITOR = 'monitor';
    const RISK_WARN = 'warn';
    const RISK_CALL = 'call';
    const RISK_EMERGENCY = 'emergency';
    
    // Constantes de human_status (independiente del ai_status)
    const HUMAN_STATUS_PENDING = 'pending';
    const HUMAN_STATUS_REVIEWED = 'reviewed';
    const HUMAN_STATUS_FLAGGED = 'flagged';
    const HUMAN_STATUS_RESOLVED = 'resolved';
    const HUMAN_STATUS_FALSE_POSITIVE = 'false_positive';
    
    // Constantes de verdict (normalizado)
    const VERDICT_REAL_PANIC = 'real_panic';
    const VERDICT_CONFIRMED_VIOLATION = 'confirmed_violation';
    const VERDICT_NEEDS_REVIEW = 'needs_review';
    const VERDICT_UNCERTAIN = 'uncertain';
    const VERDICT_LIKELY_FALSE_POSITIVE = 'likely_false_positive';
    const VERDICT_NO_ACTION_NEEDED = 'no_action_needed';
    const VERDICT_RISK_DETECTED = 'risk_detected';
    
    // Constantes de likelihood (normalizado)
    const LIKELIHOOD_HIGH = 'high';
    const LIKELIHOOD_MEDIUM = 'medium';
    const LIKELIHOOD_LOW = 'low';
    
    // Constantes de alert_kind (normalizado)
    const ALERT_KIND_PANIC = 'panic';
    const ALERT_KIND_SAFETY = 'safety';
    const ALERT_KIND_TAMPERING = 'tampering';
    const ALERT_KIND_CONNECTIVITY = 'connectivity';
    const ALERT_KIND_UNKNOWN = 'unknown';

    /**
     * ========================================
     * RELACIONES
     * ========================================
     */
    
    /**
     * Company that owns this event.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
    
    /**
     * Usuario que revisó el evento.
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
    
    /**
     * Comentarios del evento.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(SamsaraEventComment::class)->orderBy('created_at', 'desc');
    }
    
    /**
     * Actividades/audit trail del evento.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(SamsaraEventActivity::class)->orderBy('created_at', 'desc');
    }
    
    /**
     * ========================================
     * RELACIONES NORMALIZADAS
     * ========================================
     */
    
    /**
     * Acciones recomendadas (normalizado desde ai_assessment.recommended_actions).
     */
    public function recommendedActions(): HasMany
    {
        return $this->hasMany(EventRecommendedAction::class, 'samsara_event_id')
            ->orderBy('display_order');
    }
    
    /**
     * Pasos de investigación (normalizado desde alert_context.investigation_plan).
     */
    public function investigationSteps(): HasMany
    {
        return $this->hasMany(EventInvestigationStep::class, 'samsara_event_id')
            ->orderBy('step_order');
    }
    
    /**
     * Decisión de notificación (normalizado desde notification_decision).
     */
    public function notificationDecisionRecord(): HasOne
    {
        return $this->hasOne(NotificationDecision::class, 'samsara_event_id');
    }
    
    /**
     * Resultados de notificaciones (normalizado desde notification_execution.results).
     */
    public function notificationResults(): HasMany
    {
        return $this->hasMany(NotificationResult::class, 'samsara_event_id')
            ->orderBy('timestamp_utc');
    }
    
    /**
     * ========================================
     * RELACIONES DE CORRELACIÓN
     * ========================================
     */
    
    /**
     * Incidente al que pertenece este evento.
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(AlertIncident::class, 'incident_id');
    }
    
    /**
     * Correlaciones de este evento (cuando es parte de un incidente).
     */
    public function correlations(): HasMany
    {
        return $this->hasMany(AlertCorrelation::class, 'samsara_event_id');
    }
    
    /**
     * Incidente donde este evento es el primario.
     */
    public function primaryIncident(): HasOne
    {
        return $this->hasOne(AlertIncident::class, 'primary_event_id');
    }

    /**
     * ========================================
     * SCOPES
     * ========================================
     */
    
    /**
     * Scopes para filtrar eventos (AI status)
     */
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

    public function scopeFailed($query)
    {
        return $query->where('ai_status', self::STATUS_FAILED);
    }

    public function scopeInvestigating($query)
    {
        return $query->where('ai_status', self::STATUS_INVESTIGATING);
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
    
    /**
     * Scope a query to only include events for a specific company.
     */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
    
    /**
     * Scopes para filtrar por human_status
     */
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
    
    /**
     * Scope para alertas que requieren atención humana.
     * Lógica: AI no completó o está en estados que sugieren revisión.
     */
    public function scopeNeedsHumanAttention($query)
    {
        return $query->where('human_status', self::HUMAN_STATUS_PENDING)
            ->where(function ($q) {
                $q->whereIn('ai_status', [self::STATUS_FAILED, self::STATUS_INVESTIGATING])
                  ->orWhere('severity', self::SEVERITY_CRITICAL)
                  ->orWhereIn('risk_escalation', [self::RISK_CALL, self::RISK_EMERGENCY]);
            });
    }
    
    /**
     * ========================================
     * SCOPES PARA CAMPOS NORMALIZADOS
     * ========================================
     */
    
    /**
     * Scope por verdict.
     */
    public function scopeByVerdict($query, string $verdict)
    {
        return $query->where('verdict', $verdict);
    }
    
    /**
     * Scope por likelihood.
     */
    public function scopeByLikelihood($query, string $likelihood)
    {
        return $query->where('likelihood', $likelihood);
    }
    
    /**
     * Scope por alert_kind.
     */
    public function scopeByAlertKind($query, string $alertKind)
    {
        return $query->where('alert_kind', $alertKind);
    }
    
    /**
     * Scope para eventos de pánico.
     */
    public function scopePanicAlerts($query)
    {
        return $query->where('alert_kind', self::ALERT_KIND_PANIC);
    }
    
    /**
     * Scope para eventos de seguridad.
     */
    public function scopeSafetyAlerts($query)
    {
        return $query->where('alert_kind', self::ALERT_KIND_SAFETY);
    }
    
    /**
     * Scope para eventos de tampering.
     */
    public function scopeTamperingAlerts($query)
    {
        return $query->where('alert_kind', self::ALERT_KIND_TAMPERING);
    }
    
    /**
     * Scope para eventos con alta confianza.
     */
    public function scopeHighConfidence($query, float $threshold = 0.8)
    {
        return $query->where('confidence', '>=', $threshold);
    }
    
    /**
     * Scope para eventos que son parte de un incidente.
     */
    public function scopePartOfIncident($query)
    {
        return $query->whereNotNull('incident_id');
    }
    
    /**
     * Scope para eventos primarios de incidentes.
     */
    public function scopePrimaryEvents($query)
    {
        return $query->where('is_primary_event', true);
    }
    
    /**
     * Scope para eventos relacionados con un vehículo en una ventana de tiempo.
     */
    public function scopeRelatedToVehicle($query, string $vehicleId, $startTime, $endTime)
    {
        return $query->where('vehicle_id', $vehicleId)
            ->whereBetween('occurred_at', [$startTime, $endTime]);
    }

    /**
     * ========================================
     * MÉTODOS HELPER - AI STATUS
     * ========================================
     */
    public function markAsProcessing(): void
    {
        $this->update(['ai_status' => self::STATUS_PROCESSING]);
    }

    /**
     * Marca el evento como completado con el nuevo contrato.
     * 
     * @param array $assessment Evaluación técnica (assessment)
     * @param string $humanMessage Mensaje para humanos (human_message)
     * @param array|null $alertContext Contexto del triage (alert_context)
     * @param array|null $notificationDecision Decisión de notificación
     * @param array|null $notificationExecution Resultados de ejecución
     * @param array|null $execution Metadatos de ejecución (ai_actions)
     */
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
            'ai_assessment' => $assessment,
            'ai_message' => $humanMessage,
            'ai_processed_at' => now(),
        ];
        
        // Campos opcionales del nuevo contrato
        if ($alertContext !== null) {
            $data['alert_context'] = $alertContext;
            $data['proactive_flag'] = $alertContext['proactive_flag'] ?? false;
        }
        
        if ($notificationDecision !== null) {
            $data['notification_decision'] = $notificationDecision;
        }
        
        if ($notificationExecution !== null) {
            $data['notification_execution'] = $notificationExecution;
        }
        
        if ($execution !== null) {
            $data['ai_actions'] = $execution;
        }
        
        // Extraer campos operativos del assessment
        if (isset($assessment['dedupe_key'])) {
            $data['dedupe_key'] = $assessment['dedupe_key'];
        }
        
        if (isset($assessment['risk_escalation'])) {
            $data['risk_escalation'] = $assessment['risk_escalation'];
        }
        
        if (isset($assessment['supporting_evidence']['data_consistency'])) {
            $data['data_consistency'] = $assessment['supporting_evidence']['data_consistency'];
        }
        
        if (isset($assessment['recommended_actions'])) {
            $data['recommended_actions'] = $assessment['recommended_actions'];
        }
        
        $this->update($data);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'ai_status' => self::STATUS_FAILED,
            'ai_error' => $error,
            'ai_processed_at' => now(),
        ]);
    }

    /**
     * Marca el evento como en investigación con el nuevo contrato.
     */
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
            'ai_assessment' => $assessment,
            'ai_message' => $humanMessage,
            'last_investigation_at' => now(),
            'investigation_count' => $this->investigation_count + 1,
            'next_check_minutes' => $nextCheckMinutes,
        ];
        
        // Campos opcionales del nuevo contrato
        if ($alertContext !== null) {
            $data['alert_context'] = $alertContext;
            $data['proactive_flag'] = $alertContext['proactive_flag'] ?? false;
        }
        
        if ($notificationDecision !== null) {
            $data['notification_decision'] = $notificationDecision;
        }
        
        if ($notificationExecution !== null) {
            $data['notification_execution'] = $notificationExecution;
        }
        
        if ($execution !== null) {
            $data['ai_actions'] = $execution;
        }
        
        // Extraer campos operativos del assessment
        if (isset($assessment['dedupe_key'])) {
            $data['dedupe_key'] = $assessment['dedupe_key'];
        }
        
        if (isset($assessment['risk_escalation'])) {
            $data['risk_escalation'] = $assessment['risk_escalation'];
        }
        
        if (isset($assessment['supporting_evidence']['data_consistency'])) {
            $data['data_consistency'] = $assessment['supporting_evidence']['data_consistency'];
        }
        
        if (isset($assessment['recommended_actions'])) {
            $data['recommended_actions'] = $assessment['recommended_actions'];
        }
        
        $this->update($data);
    }

    /**
     * Verificar si el evento está procesado
     */
    public function isProcessed(): bool
    {
        return in_array($this->ai_status, [self::STATUS_COMPLETED, self::STATUS_FAILED]);
    }

    /**
     * Verificar si es crítico
     */
    public function isCritical(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL;
    }
    
    /**
     * Verificar si es proactivo (tampering/obstrucción/conectividad)
     */
    public function isProactive(): bool
    {
        return $this->proactive_flag === true;
    }
    
    /**
     * Verificar si requiere escalación urgente
     */
    public function requiresUrgentEscalation(): bool
    {
        return in_array($this->risk_escalation, [self::RISK_CALL, self::RISK_EMERGENCY]);
    }

    /**
     * Verificar si debe revalidarse
     */
    public function shouldRevalidate(): bool
    {
        if ($this->ai_status !== self::STATUS_INVESTIGATING) {
            return false;
        }

        if (!$this->last_investigation_at || !$this->next_check_minutes) {
            return true;
        }

        return now()->diffInMinutes($this->last_investigation_at) >= $this->next_check_minutes;
    }

    /**
     * Agregar/actualizar registro de investigación al historial.
     * 
     * Si el último registro del historial es de la misma investigación (mismo count),
     * actualiza ese registro con la razón del AI. Si no, agrega uno nuevo.
     * 
     * Esto evita duplicados cuando persistRevalidationWindow ya agregó
     * la información de la ventana temporal.
     */
    public function addInvestigationRecord(string $reason): void
    {
        $history = $this->investigation_history ?? [];
        
        // Verificar si el último registro es de esta misma investigación
        $lastIndex = count($history) - 1;
        if ($lastIndex >= 0 && isset($history[$lastIndex]['investigation_number'])) {
            // Formato nuevo: actualizar el último registro con el reason del AI
            $history[$lastIndex]['ai_reason'] = $reason;
            $history[$lastIndex]['ai_evaluated_at'] = now()->toIso8601String();
        } else {
            // Formato legacy o historial vacío: agregar registro simple
            $history[] = [
                'timestamp' => now()->toIso8601String(),
                'reason' => $reason,
                'count' => $this->investigation_count,
            ];
        }

        $this->update(['investigation_history' => $history]);
    }
    
    /**
     * Verificar si hay conflicto de datos (driver, etc.)
     */
    public function hasDataConflict(): bool
    {
        $consistency = $this->data_consistency ?? [];
        return $consistency['has_conflict'] ?? false;
    }
    
    /**
     * Obtener la primera acción recomendada
     */
    public function getFirstRecommendedAction(): ?string
    {
        $actions = $this->recommended_actions ?? [];
        return $actions[0] ?? null;
    }

    /**
     * Máximo de investigaciones permitidas
     */
    public static function getMaxInvestigations(): int
    {
        return 3;
    }

    /**
     * ========================================
     * MÉTODOS HELPER - HUMAN STATUS
     * ========================================
     */
    
    /**
     * Marcar como revisado por un humano.
     */
    public function markAsHumanReviewed(int $userId): void
    {
        $oldStatus = $this->human_status;
        
        $this->update([
            'human_status' => self::HUMAN_STATUS_REVIEWED,
            'reviewed_by_id' => $userId,
            'reviewed_at' => now(),
        ]);
        
        $this->logHumanStatusChange($userId, $oldStatus, self::HUMAN_STATUS_REVIEWED);
    }
    
    /**
     * Marcar como flagged (requiere seguimiento).
     */
    public function markAsHumanFlagged(int $userId): void
    {
        $oldStatus = $this->human_status;
        
        $this->update([
            'human_status' => self::HUMAN_STATUS_FLAGGED,
            'reviewed_by_id' => $userId,
            'reviewed_at' => now(),
        ]);
        
        $this->logHumanStatusChange($userId, $oldStatus, self::HUMAN_STATUS_FLAGGED);
    }
    
    /**
     * Marcar como resuelto por humano.
     */
    public function markAsHumanResolved(int $userId): void
    {
        $oldStatus = $this->human_status;
        
        $this->update([
            'human_status' => self::HUMAN_STATUS_RESOLVED,
            'reviewed_by_id' => $userId,
            'reviewed_at' => now(),
        ]);
        
        $this->logHumanStatusChange($userId, $oldStatus, self::HUMAN_STATUS_RESOLVED);
    }
    
    /**
     * Marcar como falso positivo.
     */
    public function markAsHumanFalsePositive(int $userId): void
    {
        $oldStatus = $this->human_status;
        
        $this->update([
            'human_status' => self::HUMAN_STATUS_FALSE_POSITIVE,
            'reviewed_by_id' => $userId,
            'reviewed_at' => now(),
        ]);
        
        $this->logHumanStatusChange($userId, $oldStatus, self::HUMAN_STATUS_FALSE_POSITIVE);
    }
    
    /**
     * Cambiar human_status de forma genérica.
     */
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
        
        $this->logHumanStatusChange($userId, $oldStatus, $status);
    }
    
    /**
     * Helper para loggear cambio de human_status.
     */
    protected function logHumanStatusChange(int $userId, string $oldStatus, string $newStatus): void
    {
        SamsaraEventActivity::logHumanAction(
            $this->id,
            $this->company_id,
            $userId,
            SamsaraEventActivity::ACTION_HUMAN_STATUS_CHANGED,
            [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]
        );
    }
    
    /**
     * Agregar un comentario al evento.
     */
    public function addComment(int $userId, string $content): SamsaraEventComment
    {
        $comment = $this->comments()->create([
            'user_id' => $userId,
            'content' => $content,
        ]);
        
        // Loggear la actividad
        SamsaraEventActivity::logHumanAction(
            $this->id,
            $this->company_id,
            $userId,
            SamsaraEventActivity::ACTION_COMMENT_ADDED,
            ['comment_id' => $comment->id]
        );
        
        return $comment;
    }
    
    /**
     * Verificar si ha sido revisado por un humano.
     */
    public function isHumanReviewed(): bool
    {
        return $this->human_status !== self::HUMAN_STATUS_PENDING;
    }
    
    /**
     * Verificar si requiere atención humana.
     */
    public function needsHumanAttention(): bool
    {
        if ($this->human_status !== self::HUMAN_STATUS_PENDING) {
            return false;
        }
        
        return $this->ai_status === self::STATUS_FAILED
            || $this->ai_status === self::STATUS_INVESTIGATING
            || $this->severity === self::SEVERITY_CRITICAL
            || $this->requiresUrgentEscalation();
    }
    
    /**
     * Obtener el nivel de urgencia para UI.
     * 
     * @return string 'high', 'medium', 'low'
     */
    public function getHumanUrgencyLevel(): string
    {
        // Ya revisado = bajo
        if ($this->human_status !== self::HUMAN_STATUS_PENDING) {
            return 'low';
        }
        
        // AI falló o es crítico = alto
        if ($this->ai_status === self::STATUS_FAILED || $this->severity === self::SEVERITY_CRITICAL) {
            return 'high';
        }
        
        // Requiere escalación urgente = alto
        if ($this->requiresUrgentEscalation()) {
            return 'high';
        }
        
        // Investigando con múltiples intentos = medio
        if ($this->ai_status === self::STATUS_INVESTIGATING && $this->investigation_count >= 2) {
            return 'medium';
        }
        
        // Investigando = medio
        if ($this->ai_status === self::STATUS_INVESTIGATING) {
            return 'medium';
        }
        
        return 'low';
    }
    
    /**
     * ========================================
     * MÉTODOS HELPER - CAMPOS NORMALIZADOS
     * ========================================
     */
    
    /**
     * Obtener etiqueta legible para verdict.
     */
    public function getVerdictLabel(): string
    {
        return match($this->verdict) {
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
    
    /**
     * Obtener etiqueta legible para likelihood.
     */
    public function getLikelihoodLabel(): string
    {
        return match($this->likelihood) {
            self::LIKELIHOOD_HIGH => 'Alta',
            self::LIKELIHOOD_MEDIUM => 'Media',
            self::LIKELIHOOD_LOW => 'Baja',
            default => $this->likelihood ?? 'Sin evaluación',
        };
    }
    
    /**
     * Obtener etiqueta legible para alert_kind.
     */
    public function getAlertKindLabel(): string
    {
        return match($this->alert_kind) {
            self::ALERT_KIND_PANIC => 'Pánico',
            self::ALERT_KIND_SAFETY => 'Seguridad',
            self::ALERT_KIND_TAMPERING => 'Manipulación',
            self::ALERT_KIND_CONNECTIVITY => 'Conectividad',
            self::ALERT_KIND_UNKNOWN => 'Desconocido',
            default => $this->alert_kind ?? 'Sin clasificar',
        };
    }
    
    /**
     * Verificar si tiene un veredicto de alto riesgo.
     */
    public function hasHighRiskVerdict(): bool
    {
        return in_array($this->verdict, [
            self::VERDICT_REAL_PANIC,
            self::VERDICT_CONFIRMED_VIOLATION,
            self::VERDICT_RISK_DETECTED,
        ]);
    }
    
    /**
     * Verificar si es un falso positivo probable o confirmado.
     */
    public function isProbableFalsePositive(): bool
    {
        return $this->verdict === self::VERDICT_LIKELY_FALSE_POSITIVE
            || $this->human_status === self::HUMAN_STATUS_FALSE_POSITIVE;
    }
    
    /**
     * Obtener acciones recomendadas como array de strings.
     * Prioriza la tabla normalizada, cae back a JSON legacy.
     */
    public function getRecommendedActionsArray(): array
    {
        // Primero intentar desde la tabla normalizada
        $actions = $this->recommendedActions()->pluck('action_text')->toArray();
        
        if (!empty($actions)) {
            return $actions;
        }
        
        // Fallback a JSON legacy
        return $this->recommended_actions ?? [];
    }
    
    /**
     * Obtener pasos de investigación como array de strings.
     * Prioriza la tabla normalizada, cae back a JSON legacy.
     */
    public function getInvestigationStepsArray(): array
    {
        // Primero intentar desde la tabla normalizada
        $steps = $this->investigationSteps()->pluck('step_text')->toArray();
        
        if (!empty($steps)) {
            return $steps;
        }
        
        // Fallback a JSON legacy
        return $this->alert_context['investigation_plan'] ?? [];
    }
    
    /**
     * ========================================
     * MÉTODOS HELPER - CORRELACIÓN
     * ========================================
     */
    
    /**
     * Verificar si este evento es parte de un incidente.
     */
    public function isPartOfIncident(): bool
    {
        return $this->incident_id !== null;
    }
    
    /**
     * Verificar si este evento es el primario de un incidente.
     */
    public function isPrimaryOfIncident(): bool
    {
        return $this->is_primary_event === true;
    }
    
    /**
     * Obtener eventos correlacionados del mismo incidente.
     */
    public function getCorrelatedEvents(): \Illuminate\Database\Eloquent\Collection
    {
        if (!$this->incident_id) {
            return collect();
        }
        
        return self::where('incident_id', $this->incident_id)
            ->where('id', '!=', $this->id)
            ->orderBy('occurred_at')
            ->get();
    }
    
    /**
     * Guardar acciones recomendadas en tabla normalizada.
     */
    public function saveRecommendedActions(array $actions): void
    {
        EventRecommendedAction::replaceForEvent($this->id, $actions);
    }
    
    /**
     * Guardar pasos de investigación en tabla normalizada.
     */
    public function saveInvestigationSteps(array $steps): void
    {
        EventInvestigationStep::replaceForEvent($this->id, $steps);
    }
    
    /**
     * Guardar decisión de notificación en tabla normalizada.
     */
    public function saveNotificationDecision(array $decisionData, array $recipients = []): NotificationDecision
    {
        // Eliminar decisión anterior si existe
        $this->notificationDecisionRecord()->delete();
        
        // Crear nueva decisión
        $decisionData['samsara_event_id'] = $this->id;
        return NotificationDecision::createWithRecipients($decisionData, $recipients);
    }
    
    /**
     * Registrar resultado de notificación.
     */
    public function recordNotificationResult(array $resultData): NotificationResult
    {
        $resultData['samsara_event_id'] = $this->id;
        return NotificationResult::create($resultData);
    }
}

