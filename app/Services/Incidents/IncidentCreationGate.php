<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Models\Company;
use App\Models\Incident;
use App\Models\SamsaraEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Gate for incident creation with deduplication logic.
 * 
 * Prevents duplicate incidents by:
 * - Checking unique constraints (samsara_event_id, dedupe_key)
 * - Returning existing incident on constraint violation
 */
class IncidentCreationGate
{
    public function __construct(
        protected IncidentService $incidentService
    ) {}

    /**
     * Create an incident from a webhook, with dedupe by samsara_event_id.
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
            return $this->incidentService->createFromWebhook($event, $assessment);
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
