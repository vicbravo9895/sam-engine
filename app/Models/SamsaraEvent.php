<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
 */
class SamsaraEvent extends Model
{
    use HasFactory;

    protected $fillable = [
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

    /**
     * Scopes para filtrar eventos
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
     * Métodos helper para cambiar estado
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
}

