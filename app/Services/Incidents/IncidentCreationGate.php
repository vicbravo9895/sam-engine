<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Models\Company;
use App\Models\Incident;
use App\Models\SafetySignal;
use App\Models\SamsaraEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Gate for incident creation with deduplication logic.
 * 
 * Prevents duplicate incidents by:
 * - Checking unique constraints (samsara_event_id, dedupe_key)
 * - Returning existing incident on constraint violation
 * 
 * Also links related SafetySignals as supporting evidence.
 */
class IncidentCreationGate
{
    /**
     * Time window (in minutes) to search for related SafetySignals.
     */
    private const SIGNAL_SEARCH_WINDOW_MINUTES = 5;

    public function __construct(
        protected IncidentService $incidentService
    ) {}

    /**
     * Create an incident from a webhook, with dedupe by samsara_event_id.
     * 
     * Also searches for related SafetySignals in a time window and links them
     * as supporting evidence.
     * 
     * Returns null if duplicate, or the created/existing incident.
     */
    public function createFromWebhook(SamsaraEvent $event, array $assessment = []): ?Incident
    {
        // Check if an incident already exists for this samsara_event_id
        $existing = Incident::where('company_id', $event->company_id)
            ->where('samsara_event_id', $event->samsara_event_id)
            ->first();
        
        if ($existing) {
            Log::info('Incident already exists for samsara_event_id', [
                'existing_incident_id' => $existing->id,
                'samsara_event_id' => $event->samsara_event_id,
            ]);
            return $existing;
        }
        
        try {
            $incident = $this->incidentService->createFromWebhook($event, $assessment);
            
            // Link related SafetySignals as supporting evidence
            if ($incident) {
                $this->linkRelatedSignals($incident, $event);
            }
            
            return $incident;
        } catch (QueryException $e) {
            // Handle race condition: unique constraint violation
            if ($this->isUniqueConstraintViolation($e)) {
                Log::info('Race condition handled: incident already created', [
                    'samsara_event_id' => $event->samsara_event_id,
                ]);
                
                return Incident::where('company_id', $event->company_id)
                    ->where('samsara_event_id', $event->samsara_event_id)
                    ->first();
            }
            
            throw $e;
        }
    }

    /**
     * Search for and link related SafetySignals to an incident.
     * 
     * Searches for signals from the same vehicle/driver within a time window
     * around the event occurrence.
     */
    protected function linkRelatedSignals(Incident $incident, SamsaraEvent $event): void
    {
        if (!$event->occurred_at) {
            return;
        }

        $windowMinutes = self::SIGNAL_SEARCH_WINDOW_MINUTES;
        $startTime = $event->occurred_at->copy()->subMinutes($windowMinutes);
        $endTime = $event->occurred_at->copy()->addMinutes($windowMinutes);

        // Build query for related signals
        $query = SafetySignal::where('company_id', $event->company_id)
            ->whereBetween('occurred_at', [$startTime, $endTime]);

        // Search by vehicle_id or driver_id (whichever is available)
        if ($event->vehicle_id) {
            $query->where('vehicle_id', $event->vehicle_id);
        } elseif ($event->driver_id) {
            $query->where('driver_id', $event->driver_id);
        } else {
            // No vehicle or driver to match against
            return;
        }

        $signals = $query->get();

        if ($signals->isEmpty()) {
            Log::debug('No related SafetySignals found for incident', [
                'incident_id' => $incident->id,
                'vehicle_id' => $event->vehicle_id,
                'driver_id' => $event->driver_id,
                'time_window' => "±{$windowMinutes} minutes",
            ]);
            return;
        }

        // Link signals as supporting evidence
        $incident->linkSignals($signals, 'supporting');

        Log::info('Linked SafetySignals to incident', [
            'incident_id' => $incident->id,
            'signal_count' => $signals->count(),
            'vehicle_id' => $event->vehicle_id,
            'time_window' => "±{$windowMinutes} minutes",
        ]);
    }

    /**
     * Create an incident from an auto-detected pattern with dedupe by dedupe_key.
     */
    public function createFromAutoCandidate(
        int $companyId,
        string $incidentType,
        string $dedupeKey,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $subjectName = null,
        array $metadata = []
    ): ?Incident {
        // Check if an incident already exists with this dedupe_key
        $existing = Incident::where('company_id', $companyId)
            ->where('dedupe_key', $dedupeKey)
            ->first();
        
        if ($existing) {
            Log::info('Incident already exists for dedupe_key', [
                'existing_incident_id' => $existing->id,
                'dedupe_key' => $dedupeKey,
            ]);
            return $existing;
        }
        
        try {
            return $this->incidentService->createFromPattern(
                $companyId,
                $incidentType,
                $subjectType,
                $subjectId,
                $subjectName,
                $dedupeKey,
                $metadata
            );
        } catch (QueryException $e) {
            // Handle race condition: unique constraint violation
            if ($this->isUniqueConstraintViolation($e)) {
                Log::info('Race condition handled: incident already created', [
                    'dedupe_key' => $dedupeKey,
                ]);
                
                return Incident::where('company_id', $companyId)
                    ->where('dedupe_key', $dedupeKey)
                    ->first();
            }
            
            throw $e;
        }
    }

    /**
     * Check if this is a good candidate for creating an incident.
     * 
     * This evaluates whether the event warrants creating a new incident
     * based on severity, verdict, and other factors.
     */
    public function shouldCreateIncident(SamsaraEvent $event, array $assessment = []): bool
    {
        // Always create for critical severity
        if ($event->severity === 'critical') {
            return true;
        }
        
        // Create for high-risk verdicts
        $highRiskVerdicts = [
            SamsaraEvent::VERDICT_REAL_PANIC,
            SamsaraEvent::VERDICT_CONFIRMED_VIOLATION,
            SamsaraEvent::VERDICT_RISK_DETECTED,
        ];
        
        if (in_array($assessment['verdict'] ?? $event->verdict, $highRiskVerdicts, true)) {
            return true;
        }
        
        // Create for urgent escalations
        $urgentEscalations = ['call', 'emergency'];
        if (in_array($assessment['risk_escalation'] ?? $event->risk_escalation, $urgentEscalations, true)) {
            return true;
        }
        
        // Don't create for likely false positives
        if (($assessment['verdict'] ?? $event->verdict) === SamsaraEvent::VERDICT_LIKELY_FALSE_POSITIVE) {
            return false;
        }
        
        // Don't create for info severity by default
        if ($event->severity === 'info') {
            return false;
        }
        
        // Create for warning severity with medium+ likelihood
        if ($event->severity === 'warning') {
            $likelihood = $assessment['likelihood'] ?? $event->likelihood;
            return in_array($likelihood, ['high', 'medium'], true);
        }
        
        return false;
    }

    /**
     * Check if exception is a unique constraint violation.
     */
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        // PostgreSQL unique violation code
        $pgUniqueViolation = '23505';
        
        // MySQL duplicate entry code  
        $mysqlDuplicateEntry = 1062;
        
        $errorCode = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;
        
        return $errorCode === $pgUniqueViolation || $driverCode === $mysqlDuplicateEntry;
    }
}
