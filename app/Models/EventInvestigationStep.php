<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para pasos de investigación de eventos.
 * 
 * Almacena los pasos de investigación definidos por el triage.
 * Anteriormente almacenado en alert_context.investigation_plan JSON.
 */
class EventInvestigationStep extends Model
{
    use HasFactory;

    /**
     * Disable timestamps - only using created_at.
     */
    public $timestamps = false;

    protected $fillable = [
        'samsara_event_id',
        'step_text',
        'step_order',
        'created_at',
    ];

    protected $casts = [
        'step_order' => 'integer',
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
     * Event this step belongs to.
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
     * Create multiple steps for an event from an array.
     */
    public static function createFromArray(int $eventId, array $steps): void
    {
        foreach ($steps as $index => $stepText) {
            self::create([
                'samsara_event_id' => $eventId,
                'step_text' => $stepText,
                'step_order' => $index,
            ]);
        }
    }

    /**
     * Replace all steps for an event.
     */
    public static function replaceForEvent(int $eventId, array $steps): void
    {
        // Delete existing steps
        self::where('samsara_event_id', $eventId)->delete();
        
        // Create new steps
        self::createFromArray($eventId, $steps);
    }
}
