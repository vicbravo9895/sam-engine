<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para métricas diarias de eventos.
 * 
 * Almacena métricas agregadas por día para análisis de negocio.
 * Poblado por CalculateEventMetricsJob.
 */
class EventMetric extends Model
{
    use HasFactory;

    /**
     * Disable timestamps - only using created_at.
     */
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'metric_date',
        'total_events',
        'critical_events',
        'warning_events',
        'info_events',
        'real_panic_count',
        'confirmed_violation_count',
        'needs_review_count',
        'false_positive_count',
        'no_action_needed_count',
        'avg_processing_time_ms',
        'avg_response_time_minutes',
        'incidents_detected',
        'incidents_resolved',
        'notifications_sent',
        'notifications_throttled',
        'notifications_failed',
        'events_reviewed',
        'events_flagged',
        'created_at',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'total_events' => 'integer',
        'critical_events' => 'integer',
        'warning_events' => 'integer',
        'info_events' => 'integer',
        'real_panic_count' => 'integer',
        'confirmed_violation_count' => 'integer',
        'needs_review_count' => 'integer',
        'false_positive_count' => 'integer',
        'no_action_needed_count' => 'integer',
        'avg_processing_time_ms' => 'integer',
        'avg_response_time_minutes' => 'integer',
        'incidents_detected' => 'integer',
        'incidents_resolved' => 'integer',
        'notifications_sent' => 'integer',
        'notifications_throttled' => 'integer',
        'notifications_failed' => 'integer',
        'events_reviewed' => 'integer',
        'events_flagged' => 'integer',
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
     * Company this metric belongs to.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * ========================================
     * SCOPES
     * ========================================
     */

    /**
     * Scope by company.
     */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope by date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('metric_date', [$startDate, $endDate]);
    }

    /**
     * Scope for last N days.
     */
    public function scopeLastDays($query, int $days)
    {
        return $query->where('metric_date', '>=', now()->subDays($days)->toDateString());
    }

    /**
     * ========================================
     * HELPERS
     * ========================================
     */

    /**
     * Calculate false positive rate.
     */
    public function getFalsePositiveRate(): float
    {
        if ($this->total_events === 0) {
            return 0;
        }
        
        return round(($this->false_positive_count / $this->total_events) * 100, 2);
    }

    /**
     * Calculate notification success rate.
     */
    public function getNotificationSuccessRate(): float
    {
        $total = $this->notifications_sent + $this->notifications_failed;
        
        if ($total === 0) {
            return 100;
        }
        
        return round(($this->notifications_sent / $total) * 100, 2);
    }

    /**
     * Calculate incident resolution rate.
     */
    public function getIncidentResolutionRate(): float
    {
        if ($this->incidents_detected === 0) {
            return 100;
        }
        
        return round(($this->incidents_resolved / $this->incidents_detected) * 100, 2);
    }

    /**
     * Get or create metric for a company and date.
     */
    public static function getOrCreate(int $companyId, $date): self
    {
        return self::firstOrCreate(
            [
                'company_id' => $companyId,
                'metric_date' => $date,
            ],
            [
                'total_events' => 0,
                'critical_events' => 0,
                'warning_events' => 0,
                'info_events' => 0,
            ]
        );
    }

    /**
     * Increment a specific metric.
     */
    public function incrementMetric(string $metricName, int $amount = 1): void
    {
        $this->increment($metricName, $amount);
    }

    /**
     * Get summary array for API.
     */
    public function toSummaryArray(): array
    {
        return [
            'date' => $this->metric_date->toDateString(),
            'total_events' => $this->total_events,
            'critical_events' => $this->critical_events,
            'false_positive_rate' => $this->getFalsePositiveRate(),
            'notification_success_rate' => $this->getNotificationSuccessRate(),
            'incident_resolution_rate' => $this->getIncidentResolutionRate(),
            'avg_processing_time_ms' => $this->avg_processing_time_ms,
            'avg_response_time_minutes' => $this->avg_response_time_minutes,
        ];
    }
}
