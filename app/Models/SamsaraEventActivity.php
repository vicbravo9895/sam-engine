<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para audit trail de actividades en alertas de Samsara.
 * 
 * Registra todas las acciones tanto de AI como de humanos.
 * user_id NULL = acción del sistema/AI
 */
class SamsaraEventActivity extends Model
{
    use HasFactory;

    /**
     * Este modelo solo usa created_at, no updated_at.
     */
    public $timestamps = false;
    
    protected $fillable = [
        'samsara_event_id',
        'company_id',
        'user_id',
        'action',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // Constantes de acciones del sistema/AI
    const ACTION_AI_PROCESSING_STARTED = 'ai_processing_started';
    const ACTION_AI_COMPLETED = 'ai_completed';
    const ACTION_AI_FAILED = 'ai_failed';
    const ACTION_AI_INVESTIGATING = 'ai_investigating';
    const ACTION_AI_REVALIDATED = 'ai_revalidated';
    
    // Constantes de acciones humanas
    const ACTION_HUMAN_REVIEWED = 'human_reviewed';
    const ACTION_HUMAN_STATUS_CHANGED = 'human_status_changed';
    const ACTION_COMMENT_ADDED = 'comment_added';
    const ACTION_MARKED_FALSE_POSITIVE = 'marked_false_positive';
    const ACTION_MARKED_RESOLVED = 'marked_resolved';
    const ACTION_MARKED_FLAGGED = 'marked_flagged';

    /**
     * Boot del modelo para auto-asignar created_at.
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }

    /**
     * Relación con el evento de Samsara.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SamsaraEvent::class, 'samsara_event_id');
    }

    /**
     * Relación con la company.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Relación con el usuario que realizó la acción.
     * NULL si fue una acción del sistema/AI.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include activities for a specific company.
     */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope para acciones de AI.
     */
    public function scopeAiActions($query)
    {
        return $query->whereNull('user_id');
    }

    /**
     * Scope para acciones humanas.
     */
    public function scopeHumanActions($query)
    {
        return $query->whereNotNull('user_id');
    }

    /**
     * Scope para un tipo de acción específico.
     */
    public function scopeOfAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Helper para verificar si es una acción de AI.
     */
    public function isAiAction(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Helper para verificar si es una acción humana.
     */
    public function isHumanAction(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Crear una actividad de AI.
     */
    public static function logAiAction(int $eventId, ?int $companyId, string $action, ?array $metadata = null): self
    {
        return static::create([
            'samsara_event_id' => $eventId,
            'company_id' => $companyId,
            'user_id' => null,
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Crear una actividad humana.
     */
    public static function logHumanAction(int $eventId, ?int $companyId, int $userId, string $action, ?array $metadata = null): self
    {
        return static::create([
            'samsara_event_id' => $eventId,
            'company_id' => $companyId,
            'user_id' => $userId,
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }
}

