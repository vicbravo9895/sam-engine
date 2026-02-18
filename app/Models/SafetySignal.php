<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\BehaviorLabelTranslator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Log;

/**
 * Safety Signal Model.
 * 
 * Almacena safety events del stream de Samsara de forma normalizada.
 * Estos eventos son solo para referencia histórica, NO se procesan con IA.
 * 
 * Renamed from SafetyEventStream to SafetySignal for consistency
 * with the new incident correlation system.
 */
class SafetySignal extends Model
{
    use HasFactory;

    protected $table = 'safety_signals';

    // Severity levels
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    // Event states from Samsara
    public const STATE_NEEDS_REVIEW = 'needsReview';
    public const STATE_NEEDS_COACHING = 'needsCoaching';
    public const STATE_DISMISSED = 'dismissed';
    public const STATE_COACHED = 'coached';

    // Critical behavior labels
    public const CRITICAL_LABELS = [
        'Crash', 'crash', 'Collision',
        'NearCollison', 'NearCollision', 'NearPedestrianCollision',
        'ForwardCollisionWarning', 'RearCollisionWarning',
        'SevereSpeeding', 'HeavySpeeding',
        'HighSpeedSuddenDisconnect',
    ];

    // Warning behavior labels
    public const WARNING_LABELS = [
        'Acceleration', 'Braking', 'HarshTurn',
        'Speeding', 'ModerateSpeeding',
        'GenericDistraction', 'MobileUsage', 'Drowsy',
        'FollowingDistance', 'FollowingDistanceSevere',
        'NoSeatbelt', 'RanRedLight', 'RollingStop',
    ];

    /**
     * Normalize behavior label to canonical PascalCase (case-insensitive match).
     * Handles variants like noSeatbelt → NoSeatbelt, edgeRailroadCrossingViolation → EdgeRailroadCrossingViolation.
     */
    public static function normalizeBehaviorLabel(?string $label): ?string
    {
        if ($label === null || $label === '') {
            return null;
        }

        $canonical = config('safety_signals.canonical_labels', []);
        $lower = strtolower($label);

        foreach ($canonical as $canon) {
            if (strtolower($canon) === $lower) {
                return $canon;
            }
        }

        return $label;
    }

    /**
     * Check if this signal should trigger proactive notification
     * based on company's safety_stream_notify rules (AND conditions).
     *
     * A rule matches when the signal contains ALL labels in rule.conditions.
     * Returns the first matched rule array or null.
     *
     * @return array{id: string, conditions: string[], action: string}|null
     */
    public function getMatchedRule(): ?array
    {
        $company = $this->company;
        if (!$company || !$company->hasSamsaraApiKey()) {
            Log::debug('DetectionEngine: Skipped — no company or no Samsara API key', [
                'signal_id' => $this->id,
                'company_id' => $this->company_id,
            ]);
            return null;
        }

        $config = $company->getSafetyStreamNotifyConfig();

        if (!($config['enabled'] ?? true)) {
            Log::debug('DetectionEngine: Skipped — safety_stream_notify disabled', [
                'signal_id' => $this->id,
                'company_id' => $this->company_id,
            ]);
            return null;
        }

        $rules = $config['rules'] ?? [];
        if (empty($rules)) {
            Log::debug('DetectionEngine: Skipped — no rules configured', [
                'signal_id' => $this->id,
                'company_id' => $this->company_id,
            ]);
            return null;
        }

        $signalLabels = $this->getNormalizedLabels();

        if (empty($signalLabels)) {
            Log::debug('DetectionEngine: Skipped — signal has no behavior labels', [
                'signal_id' => $this->id,
                'company_id' => $this->company_id,
                'primary_behavior_label' => $this->primary_behavior_label,
            ]);
            return null;
        }

        Log::info('DetectionEngine: Evaluating rules', [
            'signal_id' => $this->id,
            'company_id' => $this->company_id,
            'signal_labels' => $signalLabels,
            'rules_count' => count($rules),
            'vehicle_name' => $this->vehicle_name,
            'primary_behavior_label' => $this->primary_behavior_label,
        ]);

        foreach ($rules as $rule) {
            $conditions = $rule['conditions'] ?? [];
            if (empty($conditions)) {
                continue;
            }

            $normalizedConditions = array_map(
                fn (string $l) => self::normalizeBehaviorLabel($l) ?? $l,
                $conditions
            );

            $allMatch = true;
            $matchDetails = [];
            foreach ($normalizedConditions as $condition) {
                $matched = in_array($condition, $signalLabels, true);
                $matchDetails[$condition] = $matched;
                if (!$matched) {
                    $allMatch = false;
                    break;
                }
            }

            Log::debug('DetectionEngine: Rule evaluation', [
                'signal_id' => $this->id,
                'rule_id' => $rule['id'] ?? 'unknown',
                'rule_conditions' => $normalizedConditions,
                'rule_action' => $rule['action'] ?? 'ai_pipeline',
                'signal_labels' => $signalLabels,
                'match_details' => $matchDetails,
                'all_match' => $allMatch,
            ]);

            if ($allMatch) {
                Log::info('DetectionEngine: Rule MATCHED', [
                    'signal_id' => $this->id,
                    'company_id' => $this->company_id,
                    'rule_id' => $rule['id'] ?? 'unknown',
                    'rule_conditions' => $normalizedConditions,
                    'rule_action' => $rule['action'] ?? 'ai_pipeline',
                    'vehicle_name' => $this->vehicle_name,
                    'driver_name' => $this->driver_name,
                ]);
                return $rule;
            }
        }

        Log::debug('DetectionEngine: No rules matched', [
            'signal_id' => $this->id,
            'company_id' => $this->company_id,
            'signal_labels' => $signalLabels,
        ]);

        return null;
    }

    /**
     * @deprecated Use getMatchedRule() instead. Kept for backward compatibility.
     */
    public function shouldTriggerProactiveNotify(): bool
    {
        return $this->getMatchedRule() !== null;
    }

    /**
     * Get all normalized behavior labels for this signal.
     *
     * Combines primary_behavior_label with behavior_labels array,
     * normalizes each one, and returns unique non-null values.
     *
     * @return string[]
     */
    public function getNormalizedLabels(): array
    {
        $labels = [];

        if ($this->primary_behavior_label) {
            $normalized = self::normalizeBehaviorLabel($this->primary_behavior_label);
            if ($normalized !== null) {
                $labels[] = $normalized;
            }
        }

        if (is_array($this->behavior_labels)) {
            foreach ($this->behavior_labels as $label) {
                $value = is_array($label) ? ($label['label'] ?? $label['name'] ?? null) : $label;
                if ($value !== null) {
                    $normalized = self::normalizeBehaviorLabel($value);
                    if ($normalized !== null) {
                        $labels[] = $normalized;
                    }
                }
            }
        }

        return array_values(array_unique($labels));
    }

    protected $fillable = [
        'company_id',
        'samsara_event_id',
        'vehicle_id',
        'vehicle_name',
        'driver_id',
        'driver_name',
        'latitude',
        'longitude',
        'address',
        'primary_behavior_label',
        'behavior_labels',
        'context_labels',
        'severity',
        'event_state',
        'max_acceleration_g',
        'speeding_metadata',
        'media_urls',
        'inbox_event_url',
        'incident_report_url',
        'occurred_at',
        'samsara_created_at',
        'samsara_updated_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'behavior_labels' => 'array',
            'context_labels' => 'array',
            'speeding_metadata' => 'array',
            'media_urls' => 'array',
            'raw_payload' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'max_acceleration_g' => 'decimal:3',
            'occurred_at' => 'datetime',
            'samsara_created_at' => 'datetime',
            'samsara_updated_at' => 'datetime',
        ];
    }

    /**
     * ========================================
     * RELATIONSHIPS
     * ========================================
     */

    /**
     * Get the company that owns this event.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Incidents this signal is linked to.
     */
    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class, 'incident_safety_signals')
            ->withPivot(['role', 'relevance_score', 'created_at']);
    }

    /**
     * Get the local vehicle record if available.
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'samsara_id')
            ->where('company_id', $this->company_id);
    }

    /**
     * Get the local driver record if available.
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id', 'samsara_id')
            ->where('company_id', $this->company_id);
    }

    /**
     * ========================================
     * COMPUTED ATTRIBUTES
     * ========================================
     */

    /**
     * Check if this signal is used as evidence in any incident.
     */
    public function getUsedInEvidenceAttribute(): bool
    {
        return $this->incidents()->exists();
    }

    /**
     * Get the primary behavior label translated to Spanish.
     */
    public function getPrimaryLabelTranslatedAttribute(): ?string
    {
        if (!$this->primary_behavior_label) {
            return null;
        }

        return BehaviorLabelTranslator::getName($this->primary_behavior_label);
    }

    /**
     * Get full translation data for the primary behavior label.
     */
    public function getPrimaryLabelDataAttribute(): ?array
    {
        if (!$this->primary_behavior_label) {
            return null;
        }

        return BehaviorLabelTranslator::translate($this->primary_behavior_label);
    }

    /**
     * Get all behavior labels translated.
     */
    public function getBehaviorLabelsTranslatedAttribute(): array
    {
        if (!$this->behavior_labels) {
            return [];
        }

        return BehaviorLabelTranslator::translateMany($this->behavior_labels);
    }

    /**
     * Get the event state translated to Spanish.
     */
    public function getEventStateTranslatedAttribute(): ?string
    {
        if (!$this->event_state) {
            return null;
        }

        return BehaviorLabelTranslator::getStateName($this->event_state);
    }

    /**
     * Get the severity label in Spanish.
     */
    public function getSeverityLabelAttribute(): string
    {
        return match ($this->severity) {
            self::SEVERITY_CRITICAL => 'Crítico',
            self::SEVERITY_WARNING => 'Advertencia',
            default => 'Información',
        };
    }

    /**
     * ========================================
     * SCOPES
     * ========================================
     */

    /**
     * Scope: Filter by company.
     */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope: Filter by severity.
     */
    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope: Filter by vehicle.
     */
    public function scopeForVehicle($query, string $vehicleId)
    {
        return $query->where('vehicle_id', $vehicleId);
    }

    /**
     * Scope: Filter by driver.
     */
    public function scopeForDriver($query, string $driverId)
    {
        return $query->where('driver_id', $driverId);
    }

    /**
     * Scope: Filter by date range.
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('occurred_at', [$startDate, $endDate]);
    }

    /**
     * Scope: Only critical events.
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    /**
     * Scope: Only events needing review.
     */
    public function scopeNeedsReview($query)
    {
        return $query->where('event_state', self::STATE_NEEDS_REVIEW);
    }

    /**
     * Scope: Signals not yet linked to any incident.
     */
    public function scopeUnlinked($query)
    {
        return $query->whereDoesntHave('incidents');
    }

    /**
     * ========================================
     * FACTORY METHODS
     * ========================================
     */

    /**
     * Enrich event data from local database records.
     */
    public static function enrichFromLocalData(int $companyId, array $attributes): array
    {
        // Enrich vehicle data if name is missing but ID exists
        if (empty($attributes['vehicle_name']) && !empty($attributes['vehicle_id'])) {
            $vehicle = Vehicle::where('company_id', $companyId)
                ->where('samsara_id', $attributes['vehicle_id'])
                ->first();
            
            if ($vehicle) {
                $attributes['vehicle_name'] = $vehicle->name;
            }
        }

        // Enrich driver data if name is missing but ID exists
        if (empty($attributes['driver_name']) && !empty($attributes['driver_id'])) {
            $driver = Driver::where('company_id', $companyId)
                ->where('samsara_id', $attributes['driver_id'])
                ->first();
            
            if ($driver) {
                $attributes['driver_name'] = $driver->name;
            }
        }

        return $attributes;
    }

    /**
     * Create from Samsara stream event data.
     */
    public static function createFromStreamEvent(int $companyId, array $eventData): self
    {
        $asset = $eventData['asset'] ?? [];
        $driver = $eventData['driver'] ?? [];
        $location = $eventData['location'] ?? [];
        $behaviorLabels = $eventData['behaviorLabels'] ?? [];
        $contextLabels = $eventData['contextLabels'] ?? [];

        // Get primary behavior label (first one)
        $primaryLabel = self::extractPrimaryLabel($behaviorLabels);

        // Determine severity
        $severity = self::determineSeverity($behaviorLabels);

        // Format address
        $address = self::formatAddress($location['address'] ?? []);

        // Parse occurred_at from various possible fields
        $occurredAt = $eventData['startMs'] 
            ?? $eventData['createdAtTime'] 
            ?? now()->toIso8601String();
        
        if (is_numeric($occurredAt)) {
            $occurredAt = \Carbon\Carbon::createFromTimestampMs($occurredAt);
        }

        // Build attributes
        $attributes = [
            'company_id' => $companyId,
            'samsara_event_id' => $eventData['id'],
            'vehicle_id' => $asset['id'] ?? null,
            'vehicle_name' => $asset['name'] ?? null,
            'driver_id' => $driver['id'] ?? null,
            'driver_name' => $driver['name'] ?? null,
            'latitude' => $location['latitude'] ?? null,
            'longitude' => $location['longitude'] ?? null,
            'address' => $address,
            'primary_behavior_label' => $primaryLabel,
            'behavior_labels' => $behaviorLabels,
            'context_labels' => $contextLabels,
            'severity' => $severity,
            'event_state' => $eventData['eventState'] ?? null,
            'max_acceleration_g' => $eventData['maxAccelerationGForce'] ?? null,
            'speeding_metadata' => $eventData['speedingMetadata'] ?? null,
            'media_urls' => $eventData['media'] ?? null,
            'inbox_event_url' => $eventData['inboxEventUrl'] ?? null,
            'incident_report_url' => $eventData['incidentReportUrl'] ?? null,
            'occurred_at' => $occurredAt,
            'samsara_created_at' => $eventData['createdAtTime'] ?? null,
            'samsara_updated_at' => $eventData['updatedAtTime'] ?? null,
            'raw_payload' => $eventData,
        ];

        // Enrich with local data if vehicle_name or driver_name are missing
        $attributes = self::enrichFromLocalData($companyId, $attributes);

        return self::create($attributes);
    }

    /**
     * Update from Samsara stream event data.
     */
    public function updateFromStreamEvent(array $eventData): self
    {
        $asset = $eventData['asset'] ?? [];
        $driver = $eventData['driver'] ?? [];
        $location = $eventData['location'] ?? [];
        $behaviorLabels = $eventData['behaviorLabels'] ?? [];
        $contextLabels = $eventData['contextLabels'] ?? [];

        $attributes = [
            'vehicle_id' => $asset['id'] ?? $this->vehicle_id,
            'vehicle_name' => $asset['name'] ?? $this->vehicle_name,
            'driver_id' => $driver['id'] ?? $this->driver_id,
            'driver_name' => $driver['name'] ?? $this->driver_name,
            'latitude' => $location['latitude'] ?? $this->latitude,
            'longitude' => $location['longitude'] ?? $this->longitude,
            'address' => self::formatAddress($location['address'] ?? []) ?? $this->address,
            'primary_behavior_label' => self::extractPrimaryLabel($behaviorLabels) ?? $this->primary_behavior_label,
            'behavior_labels' => $behaviorLabels ?: $this->behavior_labels,
            'context_labels' => $contextLabels ?: $this->context_labels,
            'severity' => self::determineSeverity($behaviorLabels),
            'event_state' => $eventData['eventState'] ?? $this->event_state,
            'max_acceleration_g' => $eventData['maxAccelerationGForce'] ?? $this->max_acceleration_g,
            'speeding_metadata' => $eventData['speedingMetadata'] ?? $this->speeding_metadata,
            'media_urls' => $eventData['media'] ?? $this->media_urls,
            'samsara_updated_at' => $eventData['updatedAtTime'] ?? now(),
            'raw_payload' => $eventData,
        ];

        // Enrich with local data if vehicle_name or driver_name are still missing
        $attributes = self::enrichFromLocalData($this->company_id, $attributes);

        $this->update($attributes);

        return $this;
    }

    /**
     * Extract primary behavior label from labels array.
     */
    private static function extractPrimaryLabel(array $behaviorLabels): ?string
    {
        if (empty($behaviorLabels)) {
            return null;
        }

        $firstLabel = $behaviorLabels[0];
        
        if (is_string($firstLabel)) {
            return $firstLabel;
        }

        return $firstLabel['label'] ?? $firstLabel['name'] ?? null;
    }

    /**
     * Determine severity based on behavior labels.
     */
    private static function determineSeverity(array $behaviorLabels): string
    {
        foreach ($behaviorLabels as $label) {
            $labelValue = is_array($label) 
                ? ($label['label'] ?? $label['name'] ?? '') 
                : $label;
            
            if (in_array($labelValue, self::CRITICAL_LABELS, true)) {
                return self::SEVERITY_CRITICAL;
            }
        }

        foreach ($behaviorLabels as $label) {
            $labelValue = is_array($label) 
                ? ($label['label'] ?? $label['name'] ?? '') 
                : $label;
            
            if (in_array($labelValue, self::WARNING_LABELS, true)) {
                return self::SEVERITY_WARNING;
            }
        }

        return self::SEVERITY_INFO;
    }

    /**
     * Format address from location data.
     */
    private static function formatAddress(array $address): ?string
    {
        if (empty($address)) {
            return null;
        }

        $parts = array_filter([
            $address['street'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['postalCode'] ?? null,
        ]);

        return !empty($parts) ? implode(', ', $parts) : null;
    }
}
