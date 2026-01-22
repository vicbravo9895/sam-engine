<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Models\Incident;
use App\Models\SafetySignal;
use App\Models\SamsaraEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing incidents.
 * 
 * Provides unified incident creation, signal linking, and resolution.
 */
class IncidentService
{
    /**
     * Create a new incident with transaction safety.
     */
    public function create(array $data): Incident
    {
        return DB::transaction(function () use ($data) {
            $incident = Incident::create($data);
            
            Log::info('Incident created', [
                'incident_id' => $incident->id,
                'company_id' => $incident->company_id,
                'type' => $incident->incident_type,
                'priority' => $incident->priority,
                'source' => $incident->source,
            ]);
            
            return $incident;
        });
    }

    /**
     * Create an incident from a webhook-triggered SamsaraEvent.
     */
    public function createFromWebhook(SamsaraEvent $event, array $assessment = []): Incident
    {
        $priority = $this->determinePriority($event, $assessment);
        $incidentType = $this->determineType($event, $assessment);
        
        return $this->create([
            'company_id' => $event->company_id,
            'incident_type' => $incidentType,
            'priority' => $priority,
            'severity' => $event->severity ?? Incident::SEVERITY_WARNING,
            'status' => Incident::STATUS_OPEN,
            'subject_type' => $event->driver_id ? Incident::SUBJECT_DRIVER : Incident::SUBJECT_VEHICLE,
            'subject_id' => $event->driver_id ?? $event->vehicle_id,
            'subject_name' => $event->driver_name ?? $event->vehicle_name,
            'source' => Incident::SOURCE_WEBHOOK,
            'samsara_event_id' => $event->samsara_event_id,
            'ai_summary' => $assessment['summary'] ?? $event->ai_message,
            'ai_assessment' => $assessment,
            'detected_at' => $event->occurred_at ?? now(),
            'metadata' => [
                'original_event_id' => $event->id,
                'event_type' => $event->event_type,
                'event_description' => $event->event_description,
            ],
        ]);
    }

    /**
     * Create an incident from an auto-detected pattern.
     */
    public function createFromPattern(
        int $companyId,
        string $patternType,
        ?string $subjectType,
        ?string $subjectId,
        ?string $subjectName,
        string $dedupeKey,
        array $metadata = []
    ): Incident {
        return $this->create([
            'company_id' => $companyId,
            'incident_type' => Incident::TYPE_PATTERN,
            'priority' => Incident::PRIORITY_P3,
            'severity' => Incident::SEVERITY_WARNING,
            'status' => Incident::STATUS_OPEN,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_name' => $subjectName,
            'source' => Incident::SOURCE_AUTO_PATTERN,
            'dedupe_key' => $dedupeKey,
            'detected_at' => now(),
            'metadata' => array_merge($metadata, [
                'pattern_type' => $patternType,
            ]),
        ]);
    }

    /**
     * Link safety signals to an incident.
     */
    public function linkSignals(Incident $incident, Collection $signals, string $role = 'supporting'): void
    {
        DB::transaction(function () use ($incident, $signals, $role) {
            $incident->linkSignals($signals, $role);
            
            Log::info('Signals linked to incident', [
                'incident_id' => $incident->id,
                'signal_count' => $signals->count(),
                'role' => $role,
            ]);
        });
    }

    /**
     * Link a single safety signal.
     */
    public function linkSignal(
        Incident $incident,
        SafetySignal $signal,
        string $role = 'supporting',
        float $relevanceScore = 0.5
    ): void {
        $incident->linkSignal($signal, $role, $relevanceScore);
    }

    /**
     * Resolve an incident.
     */
    public function resolve(Incident $incident, ?string $summary = null): void
    {
        DB::transaction(function () use ($incident, $summary) {
            $incident->markAsResolved($summary);
            
            Log::info('Incident resolved', [
                'incident_id' => $incident->id,
                'company_id' => $incident->company_id,
            ]);
        });
    }

    /**
     * Mark an incident as false positive.
     */
    public function markAsFalsePositive(Incident $incident, ?string $reason = null): void
    {
        DB::transaction(function () use ($incident, $reason) {
            $incident->markAsFalsePositive($reason);
            
            Log::info('Incident marked as false positive', [
                'incident_id' => $incident->id,
                'company_id' => $incident->company_id,
            ]);
        });
    }

    /**
     * Update incident status.
     */
    public function updateStatus(Incident $incident, string $status): void
    {
        $incident->update(['status' => $status]);
        
        Log::info('Incident status updated', [
            'incident_id' => $incident->id,
            'new_status' => $status,
        ]);
    }

    /**
     * Determine priority based on event and assessment.
     */
    protected function determinePriority(SamsaraEvent $event, array $assessment): string
    {
        // P1 for critical severity or emergency risk escalation
        if ($event->severity === 'critical' || 
            ($assessment['risk_escalation'] ?? '') === 'emergency') {
            return Incident::PRIORITY_P1;
        }
        
        // P2 for call escalation or high likelihood
        if (($assessment['risk_escalation'] ?? '') === 'call' ||
            ($assessment['likelihood'] ?? '') === 'high') {
            return Incident::PRIORITY_P2;
        }
        
        // P3 for warning severity or warn escalation
        if ($event->severity === 'warning' ||
            ($assessment['risk_escalation'] ?? '') === 'warn') {
            return Incident::PRIORITY_P3;
        }
        
        // P4 for everything else
        return Incident::PRIORITY_P4;
    }

    /**
     * Determine incident type based on event and assessment.
     */
    protected function determineType(SamsaraEvent $event, array $assessment): string
    {
        $eventType = strtolower($event->event_type ?? '');
        $eventDescription = strtolower($event->event_description ?? '');
        $alertKind = $assessment['alert_kind'] ?? $event->alert_kind ?? '';
        
        // Collision indicators
        if (str_contains($eventType, 'collision') || 
            str_contains($eventDescription, 'collision') ||
            str_contains($eventDescription, 'crash')) {
            return Incident::TYPE_COLLISION;
        }
        
        // Emergency indicators (panic button)
        if (str_contains($eventDescription, 'panic') || 
            str_contains($eventDescription, 'emergency') ||
            $alertKind === 'panic') {
            return Incident::TYPE_EMERGENCY;
        }
        
        // Tampering indicators
        if (str_contains($eventDescription, 'obstruction') ||
            str_contains($eventDescription, 'tampering') ||
            $alertKind === 'tampering') {
            return Incident::TYPE_TAMPERING;
        }
        
        // Safety violation indicators
        if ($alertKind === 'safety' ||
            str_contains($eventDescription, 'speeding') ||
            str_contains($eventDescription, 'seatbelt')) {
            return Incident::TYPE_SAFETY_VIOLATION;
        }
        
        return Incident::TYPE_UNKNOWN;
    }
}
