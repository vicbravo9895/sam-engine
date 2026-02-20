<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventInvestigationStep extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'alert_id',
        'step_text',
        'step_order',
        'created_at',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public static function createFromArray(int $alertId, array $steps): void
    {
        foreach ($steps as $index => $stepText) {
            self::create([
                'alert_id' => $alertId,
                'step_text' => $stepText,
                'step_order' => $index,
            ]);
        }
    }

    public static function replaceForAlert(int $alertId, array $steps): void
    {
        self::where('alert_id', $alertId)->delete();
        self::createFromArray($alertId, $steps);
    }
}
