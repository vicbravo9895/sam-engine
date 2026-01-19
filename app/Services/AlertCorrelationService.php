<?php

namespace App\Services;

use App\Models\AlertCorrelation;
use App\Models\AlertIncident;
use App\Models\SamsaraEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for detecting and managing alert correlations.
 * 
 * Detects patterns between related alerts and groups them into incidents.
 * Examples:
 * - harsh_braking + panic_button within 2 minutes = collision incident
 * - camera_obstruction + panic_button within 30 minutes = emergency incident
 * - multiple harsh_braking in 15 minutes = pattern incident (aggressive driving)
 */
class AlertCorrelationService
{
    /**
     * Correlation patterns configuration.
     * Each pattern defines conditions for detecting specific incident types.
     */
    protected array $patterns = [
        'collision' => [
            'description' => 'Possible collision detected',
            'window_minutes' => 5,
            'rules' => [
                ['type' => 'harsh_braking', 'paired_with' => 'panic_button', 'max_gap_seconds' => 120],
                ['type' => 'harsh_braking', 'paired_with' => 'collision', 'max_gap_seconds' => 60],
                ['type' => 'collision_warning', 'paired_with' => 'panic_button', 'max_gap_seconds' => 180],
            ],
        ],
        'emergency' => [
            'description' => 'Possible emergency situation',
            'window_minutes' => 30,
            'rules' => [
                ['type' => 'camera_obstruction', 'paired_with' => 'panic_button', 'max_gap_seconds' => 1800],
                ['type' => 'tampering', 'paired_with' => 'panic_button', 'max_gap_seconds' => 1800],
            ],
        ],
        'pattern' => [
            'description' => 'Behavioral pattern detected',
            'window_minutes' => 15,
            'rules' => [
                ['type' => 'harsh_braking', 'min_occurrences' => 3, 'window_minutes' => 15],
                ['type' => 'speeding', 'min_occurrences' => 3, 'window_minutes' => 20],
                ['type' => 'distracted_driving', 'min_occurrences' => 2, 'window_minutes' => 30],
            ],
        ],
    ];

    /**
     * Check for correlations when a new event is processed.
     * 
     * @param SamsaraEvent $event The new event to check
     * @return AlertIncident|null The incident if created, null otherwise
     */
    public function checkCorrelations(SamsaraEvent $event): ?AlertIncident
    {
        Log::info('AlertCorrelationService: Checking correlations', [
            'event_id' => $event->id,
            'event_type' => $event->event_type,
            'vehicle_id' => $event->vehicle_id,
            'occurred_at' => $event->occurred_at,
        ]);

        // Skip if event is already part of an incident
        if ($event->incident_id) {
            Log::debug('AlertCorrelationService: Event already part of incident', [
                'event_id' => $event->id,
                'incident_id' => $event->incident_id,
            ]);
            return null;
        }

        // Get related events for the same vehicle
        $relatedEvents = $this->getRelatedEvents($event);

        if ($relatedEvents->isEmpty()) {
            Log::debug('AlertCorrelationService: No related events found', [
                'event_id' => $event->id,
            ]);
            return null;
        }

        // Check each pattern
        foreach ($this->patterns as $incidentType => $pattern) {
            $incident = $this->checkPattern($event, $relatedEvents, $incidentType, $pattern);
            
            if ($incident) {
                Log::info('AlertCorrelationService: Incident created', [
                    'event_id' => $event->id,
                    'incident_id' => $incident->id,
                    'incident_type' => $incidentType,
                ]);
                return $incident;
            }
        }

        return null;
    }

    /**
     * Get related events for the same vehicle within a time window.
     */
    protected function getRelatedEvents(SamsaraEvent $event, int $windowMinutes = 30): Collection
    {
        if (!$event->vehicle_id || !$event->occurred_at) {
            return collect();
        }

        return SamsaraEvent::where('company_id', $event->company_id)
            ->where('vehicle_id', $event->vehicle_id)
            ->where('id', '!=', $event->id)
            ->whereNull('incident_id') // Not already part of an incident
            ->whereBetween('occurred_at', [
                $event->occurred_at->copy()->subMinutes($windowMinutes),
                $event->occurred_at->copy()->addMinutes($windowMinutes),
            ])
            ->orderBy('occurred_at')
            ->get();
    }

    /**
     * Check if events match a specific pattern.
     */
    protected function checkPattern(
        SamsaraEvent $event,
        Collection $relatedEvents,
        string $incidentType,
        array $pattern
    ): ?AlertIncident {
        foreach ($pattern['rules'] as $rule) {
            if (isset($rule['paired_with'])) {
                // Paired event rule
                $incident = $this->checkPairedRule($event, $relatedEvents, $incidentType, $rule, $pattern);
                if ($incident) {
                    return $incident;
                }
            } elseif (isset($rule['min_occurrences'])) {
                // Frequency rule
                $incident = $this->checkFrequencyRule($event, $relatedEvents, $incidentType, $rule, $pattern);
                if ($incident) {
                    return $incident;
                }
            }
        }

        return null;
    }

    /**
     * Check paired event rule (e.g., harsh_braking + panic_button).
     */
    protected function checkPairedRule(
        SamsaraEvent $event,
        Collection $relatedEvents,
        string $incidentType,
        array $rule,
        array $pattern
    ): ?AlertIncident {
        $eventType = $this->normalizeEventType($event->event_type);
        $ruleType = $rule['type'];
        $pairedWith = $rule['paired_with'];
        $maxGapSeconds = $rule['max_gap_seconds'];

        // Check if current event matches either side of the pair
        $currentMatches = $eventType === $ruleType || $eventType === $pairedWith;
        if (!$currentMatches) {
            return null;
        }

        $lookingFor = $eventType === $ruleType ? $pairedWith : $ruleType;

        // Find a matching paired event
        foreach ($relatedEvents as $relatedEvent) {
            $relatedType = $this->normalizeEventType($relatedEvent->event_type);
            
            if ($relatedType !== $lookingFor) {
                continue;
            }

            // Check time gap
            $timeDelta = abs($event->occurred_at->diffInSeconds($relatedEvent->occurred_at));
            if ($timeDelta > $maxGapSeconds) {
                continue;
            }

            // Found a match! Create incident
            return $this->createIncident(
                primaryEvent: $event,
                relatedEvents: collect([$relatedEvent]),
                incidentType: $incidentType,
                correlationType: AlertCorrelation::TYPE_CAUSAL,
                pattern: $pattern
            );
        }

        return null;
    }

    /**
     * Check frequency rule (e.g., 3+ harsh_braking in 15 minutes).
     */
    protected function checkFrequencyRule(
        SamsaraEvent $event,
        Collection $relatedEvents,
        string $incidentType,
        array $rule,
        array $pattern
    ): ?AlertIncident {
        $eventType = $this->normalizeEventType($event->event_type);
        $ruleType = $rule['type'];
        $minOccurrences = $rule['min_occurrences'];
        $windowMinutes = $rule['window_minutes'];

        if ($eventType !== $ruleType) {
            return null;
        }

        // Filter related events to same type within window
        $windowStart = $event->occurred_at->copy()->subMinutes($windowMinutes);
        $windowEnd = $event->occurred_at->copy()->addMinutes($windowMinutes);

        $matchingEvents = $relatedEvents->filter(function ($relatedEvent) use ($ruleType, $windowStart, $windowEnd) {
            $type = $this->normalizeEventType($relatedEvent->event_type);
            return $type === $ruleType
                && $relatedEvent->occurred_at->between($windowStart, $windowEnd);
        });

        // Include current event in count
        $totalOccurrences = $matchingEvents->count() + 1;

        if ($totalOccurrences >= $minOccurrences) {
            return $this->createIncident(
                primaryEvent: $event,
                relatedEvents: $matchingEvents,
                incidentType: $incidentType,
                correlationType: AlertCorrelation::TYPE_PATTERN,
                pattern: $pattern
            );
        }

        return null;
    }

    /**
     * Create an incident and link events.
     */
    protected function createIncident(
        SamsaraEvent $primaryEvent,
        Collection $relatedEvents,
        string $incidentType,
        string $correlationType,
        array $pattern
    ): AlertIncident {
        return DB::transaction(function () use ($primaryEvent, $relatedEvents, $incidentType, $correlationType, $pattern) {
            // Determine severity (highest among events)
            $allEvents = $relatedEvents->push($primaryEvent);
            $severity = $this->determineIncidentSeverity($allEvents);

            // Create incident
            $incident = AlertIncident::create([
                'company_id' => $primaryEvent->company_id,
                'incident_type' => $incidentType,
                'primary_event_id' => $primaryEvent->id,
                'severity' => $severity,
                'status' => AlertIncident::STATUS_OPEN,
                'detected_at' => now(),
                'ai_summary' => $pattern['description'],
                'metadata' => [
                    'pattern_matched' => $incidentType,
                    'events_count' => $allEvents->count(),
                    'correlation_type' => $correlationType,
                ],
            ]);

            // Update primary event
            $primaryEvent->update([
                'incident_id' => $incident->id,
                'is_primary_event' => true,
            ]);

            // Create correlations and update related events
            foreach ($relatedEvents as $relatedEvent) {
                $timeDelta = $primaryEvent->occurred_at->diffInSeconds($relatedEvent->occurred_at);
                $strength = $this->calculateCorrelationStrength($timeDelta, $correlationType);

                AlertCorrelation::create([
                    'incident_id' => $incident->id,
                    'samsara_event_id' => $relatedEvent->id,
                    'correlation_type' => $correlationType,
                    'correlation_strength' => $strength,
                    'time_delta_seconds' => $timeDelta,
                    'detected_by' => AlertCorrelation::DETECTED_BY_RULE,
                ]);

                $relatedEvent->update([
                    'incident_id' => $incident->id,
                    'is_primary_event' => false,
                ]);
            }

            Log::info('AlertCorrelationService: Incident created with correlations', [
                'incident_id' => $incident->id,
                'primary_event_id' => $primaryEvent->id,
                'correlated_events' => $relatedEvents->pluck('id')->toArray(),
            ]);

            return $incident;
        });
    }

    /**
     * Normalize event type for comparison.
     */
    protected function normalizeEventType(string $eventType): string
    {
        // Convert to lowercase and replace common variations
        $type = strtolower($eventType);
        
        $mappings = [
            'panicbutton' => 'panic_button',
            'panic button' => 'panic_button',
            'alertincident' => 'alert_incident',
            'alert incident' => 'alert_incident',
            'harshbraking' => 'harsh_braking',
            'harsh braking' => 'harsh_braking',
            'safetyevent' => 'safety_event',
            'safety event' => 'safety_event',
            'cameraobstruction' => 'camera_obstruction',
            'camera obstruction' => 'camera_obstruction',
            'collisionwarning' => 'collision_warning',
            'collision warning' => 'collision_warning',
            'distracteddriving' => 'distracted_driving',
            'distracted driving' => 'distracted_driving',
        ];

        return $mappings[$type] ?? $type;
    }

    /**
     * Determine incident severity based on events.
     */
    protected function determineIncidentSeverity(Collection $events): string
    {
        $severityOrder = [
            SamsaraEvent::SEVERITY_CRITICAL => 3,
            SamsaraEvent::SEVERITY_WARNING => 2,
            SamsaraEvent::SEVERITY_INFO => 1,
        ];

        $maxSeverity = SamsaraEvent::SEVERITY_INFO;
        $maxOrder = 1;

        foreach ($events as $event) {
            $order = $severityOrder[$event->severity] ?? 1;
            if ($order > $maxOrder) {
                $maxOrder = $order;
                $maxSeverity = $event->severity;
            }
        }

        return $maxSeverity;
    }

    /**
     * Calculate correlation strength based on time delta and type.
     */
    protected function calculateCorrelationStrength(int $timeDeltaSeconds, string $correlationType): float
    {
        // Base strength depends on correlation type
        $baseStrength = match($correlationType) {
            AlertCorrelation::TYPE_CAUSAL => 0.9,
            AlertCorrelation::TYPE_TEMPORAL => 0.7,
            AlertCorrelation::TYPE_PATTERN => 0.6,
            default => 0.5,
        };

        // Reduce strength based on time delta (decay function)
        // Closer in time = stronger correlation
        $decayFactor = 1 - (min($timeDeltaSeconds, 1800) / 1800) * 0.3;

        return round($baseStrength * $decayFactor, 2);
    }

    /**
     * Add an event to an existing incident.
     */
    public function addEventToIncident(
        SamsaraEvent $event,
        AlertIncident $incident,
        string $correlationType = AlertCorrelation::TYPE_TEMPORAL,
        string $detectedBy = AlertCorrelation::DETECTED_BY_RULE
    ): AlertCorrelation {
        $primaryEvent = $incident->primaryEvent;
        $timeDelta = $primaryEvent ? $primaryEvent->occurred_at->diffInSeconds($event->occurred_at) : 0;
        $strength = $this->calculateCorrelationStrength(abs($timeDelta), $correlationType);

        $correlation = AlertCorrelation::create([
            'incident_id' => $incident->id,
            'samsara_event_id' => $event->id,
            'correlation_type' => $correlationType,
            'correlation_strength' => $strength,
            'time_delta_seconds' => $timeDelta,
            'detected_by' => $detectedBy,
        ]);

        $event->update([
            'incident_id' => $incident->id,
            'is_primary_event' => false,
        ]);

        // Update incident severity if needed
        if ($this->severityIsHigher($event->severity, $incident->severity)) {
            $incident->update(['severity' => $event->severity]);
        }

        return $correlation;
    }

    /**
     * Check if severity A is higher than severity B.
     */
    protected function severityIsHigher(string $a, string $b): bool
    {
        $order = [
            SamsaraEvent::SEVERITY_INFO => 1,
            SamsaraEvent::SEVERITY_WARNING => 2,
            SamsaraEvent::SEVERITY_CRITICAL => 3,
        ];

        return ($order[$a] ?? 0) > ($order[$b] ?? 0);
    }

    /**
     * Get open incidents for a company.
     */
    public function getOpenIncidents(int $companyId): Collection
    {
        return AlertIncident::forCompany($companyId)
            ->unresolved()
            ->with(['primaryEvent', 'correlations.event'])
            ->orderByDesc('detected_at')
            ->get();
    }

    /**
     * Get incident summary for an event.
     */
    public function getIncidentSummary(SamsaraEvent $event): ?array
    {
        if (!$event->incident_id) {
            return null;
        }

        $incident = $event->incident()->with(['primaryEvent', 'correlations.event'])->first();

        if (!$incident) {
            return null;
        }

        return [
            'incident_id' => $incident->id,
            'incident_type' => $incident->incident_type,
            'type_label' => $incident->getTypeLabel(),
            'severity' => $incident->severity,
            'status' => $incident->status,
            'status_label' => $incident->getStatusLabel(),
            'detected_at' => $incident->detected_at,
            'is_primary' => $event->is_primary_event,
            'related_events_count' => $incident->correlations->count(),
            'ai_summary' => $incident->ai_summary,
        ];
    }
}
