<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Models\Alert;
use App\Models\Incident;
use App\Models\SafetySignal;
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
     * Create an incident from an Alert (vehicle/driver data from signal).
     */
    public function createFromAlert(Alert $alert, array $assessment = []): Incident
    {
        $alert->loadMissing('signal');
        $signal = $alert->signal;

        $priority = $this->determinePriority($alert, $assessment);
        $incidentType = $this->determineType($alert, $assessment);

        $driverId = $signal?->driver_id;
        $vehicleId = $signal?->vehicle_id;
        $driverName = $signal?->driver_name;
        $vehicleName = $signal?->vehicle_name;
        
        return $this->create([
            'company_id' => $alert->company_id,
            'incident_type' => $incidentType,
            'priority' => $priority,
            'severity' => $alert->severity ?? Incident::SEVERITY_WARNING,
            'status' => Incident::STATUS_OPEN,
            'subject_type' => $driverId ? Incident::SUBJECT_DRIVER : Incident::SUBJECT_VEHICLE,
            'subject_id' => $driverId ?? $vehicleId,
            'subject_name' => $driverName ?? $vehicleName,
            'source' => Incident::SOURCE_WEBHOOK,
            'samsara_event_id' => $signal?->samsara_event_id,
            'ai_summary' => $assessment['summary'] ?? $alert->ai_message,
            'ai_assessment' => $assessment,
            'detected_at' => $alert->occurred_at ?? now(),
            'metadata' => [
                'alert_id' => $alert->id,
                'signal_id' => $signal?->id,
                'event_type' => $signal?->event_type,
                'event_description' => $alert->event_description ?? $signal?->event_description,
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
     * Determine priority based on alert and assessment.
     */
    protected function determinePriority(Alert $alert, array $assessment): string
    {
        if ($alert->severity === Alert::SEVERITY_CRITICAL || 
            ($assessment['risk_escalation'] ?? '') === Alert::RISK_EMERGENCY) {
            return Incident::PRIORITY_P1;
        }
        
        if (($assessment['risk_escalation'] ?? '') === Alert::RISK_CALL ||
            ($assessment['likelihood'] ?? '') === Alert::LIKELIHOOD_HIGH) {
            return Incident::PRIORITY_P2;
        }
        
        if ($alert->severity === Alert::SEVERITY_WARNING ||
            ($assessment['risk_escalation'] ?? '') === Alert::RISK_WARN) {
            return Incident::PRIORITY_P3;
        }
        
        return Incident::PRIORITY_P4;
    }

    /**
     * Determine incident type based on alert and assessment.
     */
    protected function determineType(Alert $alert, array $assessment): string
    {
        $alert->loadMissing('signal');
        $signal = $alert->signal;

        $eventType = strtolower($signal?->event_type ?? '');
        $eventDescription = strtolower($alert->event_description ?? $signal?->event_description ?? '');
        $alertKind = $assessment['alert_kind'] ?? $alert->alert_kind ?? '';
        
        if (str_contains($eventType, 'collision') || 
            str_contains($eventDescription, 'collision') ||
            str_contains($eventDescription, 'crash')) {
            return Incident::TYPE_COLLISION;
        }
        
        if (str_contains($eventDescription, 'panic') || 
            str_contains($eventDescription, 'emergency') ||
            $alertKind === Alert::ALERT_KIND_PANIC) {
            return Incident::TYPE_EMERGENCY;
        }
        
        if (str_contains($eventDescription, 'obstruction') ||
            str_contains($eventDescription, 'tampering') ||
            $alertKind === Alert::ALERT_KIND_TAMPERING) {
            return Incident::TYPE_TAMPERING;
        }
        
        if ($alertKind === Alert::ALERT_KIND_SAFETY ||
            str_contains($eventDescription, 'speeding') ||
            str_contains($eventDescription, 'seatbelt')) {
            return Incident::TYPE_SAFETY_VIOLATION;
        }
        
        return Incident::TYPE_UNKNOWN;
    }
}
