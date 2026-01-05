<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo para eventos de Samsara.
 * 
 * ACTUALIZADO: Nuevo contrato de respuesta del AI Service.
 * - alert_context: JSON estructurado del triage
 * - assessment: Evaluación técnica (ai_assessment)
 * - human_message: Mensaje para humanos (ai_message)
 * - notification_decision: Decisión de notificación (sin side effects)
 * - notification_execution: Resultados de ejecución real
 * - Campos operativos: dedupe_key, risk_escalation, proactive_flag, etc.
 * 
 * HUMAN REVIEW: Sistema de revisión humana independiente del AI.
 * - human_status: Estado de revisión (pending, reviewed, flagged, resolved, false_positive)
 * - reviewed_by_id: Usuario que revisó
 * - reviewed_at: Timestamp de revisión
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
        'ai_assessment',
        'ai_message',
        'ai_processed_at',
        'ai_error',
        'ai_actions',
        
        // Nuevo contrato: contexto y decisiones
        'alert_context',
        'notification_decision',
        'notification_execution',
        
        // Campos operativos estandarizados
        'dedupe_key',
        'risk_escalation',
        'proactive_flag',
        'data_consistency',
        'recommended_actions',
        
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
        
        // Nuevo contrato: campos JSON
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
     * Agregar registro de investigación al historial
     */
    public function addInvestigationRecord(string $reason): void
    {
        $history = $this->investigation_history ?? [];
        $history[] = [
            'timestamp' => now()->toIso8601String(),
            'reason' => $reason,
            'count' => $this->investigation_count,
        ];

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
}

