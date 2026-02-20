<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRecommendedAction extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'alert_id',
        'action_text',
        'display_order',
        'created_at',
    ];

    protected $casts = [
        'display_order' => 'integer',
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

    public static function createFromArray(int $alertId, array $actions): void
    {
        foreach ($actions as $index => $actionText) {
            self::create([
                'alert_id' => $alertId,
                'action_text' => $actionText,
                'display_order' => $index,
            ]);
        }
    }

    public static function replaceForAlert(int $alertId, array $actions): void
    {
        self::where('alert_id', $alertId)->delete();
        self::createFromArray($alertId, $actions);
    }
}
