<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para acciones recomendadas de eventos.
 * 
 * Almacena las acciones recomendadas por el AI para cada evento.
 * Anteriormente almacenado en ai_assessment.recommended_actions JSON.
 */
class EventRecommendedAction extends Model
{
    use HasFactory;

    /**
     * Disable timestamps - only using created_at.
     */
    public $timestamps = false;

    protected $fillable = [
        'samsara_event_id',
        'action_text',
        'display_order',
        'created_at',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Boot method to set created_at.
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
     * ========================================
     * RELATIONSHIPS
     * ========================================
     */

    /**
     * Event this action belongs to.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SamsaraEvent::class, 'samsara_event_id');
    }

    /**
     * ========================================
     * STATIC HELPERS
     * ========================================
     */

    /**
     * Create multiple actions for an event from an array.
     */
    public static function createFromArray(int $eventId, array $actions): void
    {
        foreach ($actions as $index => $actionText) {
            self::create([
                'samsara_event_id' => $eventId,
                'action_text' => $actionText,
                'display_order' => $index,
            ]);
        }
    }

    /**
     * Replace all actions for an event.
     */
    public static function replaceForEvent(int $eventId, array $actions): void
    {
        // Delete existing actions
        self::where('samsara_event_id', $eventId)->delete();
        
        // Create new actions
        self::createFromArray($eventId, $actions);
    }
}
