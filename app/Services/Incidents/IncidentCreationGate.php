<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Models\Alert;
use App\Models\Company;
use App\Models\Incident;
use App\Models\SafetySignal;
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
    private const SIGNAL_SEARCH_WINDOW_MINUTES = 5;

    public function __construct(
        protected IncidentService $incidentService
    ) {}

    /**
     * Create an incident from an Alert, with dedupe by samsara_event_id on the signal.
     * 
     * Also searches for related SafetySignals in a time window and links them
     * as supporting evidence.
     */
    public function createFromAlert(Alert $alert, array $assessment = []): ?Incident
    {
        $alert->loadMissing('signal');
        $samsaraEventId = $alert->signal?->samsara_event_id;

        if ($samsaraEventId) {
            $existing = Incident::where('company_id', $alert->company_id)
                ->where('samsara_event_id', $samsaraEventId)
                ->first();

            if ($existing) {
                Log::info('Incident already exists for samsara_event_id', [
                    'existing_incident_id' => $existing->id,
                    'samsara_event_id' => $samsaraEventId,
                ]);
                return $existing;
            }
        }

        try {
            $incident = $this->incidentService->createFromAlert($alert, $assessment);
            
            if ($incident) {
                $this->linkRelatedSignals($incident, $alert);
            }
            
            return $incident;
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e) && $samsaraEventId) {
                Log::info('Race condition handled: incident already created', [
                    'samsara_event_id' => $samsaraEventId,
                ]);
                
                return Incident::where('company_id', $alert->company_id)
                    ->where('samsara_event_id', $samsaraEventId)
                    ->first();
            }
            
            throw $e;
        }
    }

    /**
     * Search for and link related SafetySignals to an incident.
     */
    protected function linkRelatedSignals(Incident $incident, Alert $alert): void
    {
        if (!$alert->occurred_at) {
            return;
        }

        $signal = $alert->signal;
        $vehicleId = $signal?->vehicle_id;
        $driverId = $signal?->driver_id;

        $windowMinutes = self::SIGNAL_SEARCH_WINDOW_MINUTES;
        $startTime = $alert->occurred_at->copy()->subMinutes($windowMinutes);
        $endTime = $alert->occurred_at->copy()->addMinutes($windowMinutes);

        $query = SafetySignal::where('company_id', $alert->company_id)
            ->whereBetween('occurred_at', [$startTime, $endTime]);

        if ($vehicleId) {
            $query->where('vehicle_id', $vehicleId);
        } elseif ($driverId) {
            $query->where('driver_id', $driverId);
        } else {
            return;
        }

        $signals = $query->get();

        if ($signals->isEmpty()) {
            Log::debug('No related SafetySignals found for incident', [
                'incident_id' => $incident->id,
                'vehicle_id' => $vehicleId,
                'driver_id' => $driverId,
                'time_window' => "±{$windowMinutes} minutes",
            ]);
            return;
        }

        $incident->linkSignals($signals, 'supporting');

        Log::info('Linked SafetySignals to incident', [
            'incident_id' => $incident->id,
            'signal_count' => $signals->count(),
            'vehicle_id' => $vehicleId,
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
     * Check if an alert is a good candidate for creating an incident.
     */
    public function shouldCreateIncident(Alert $alert, array $assessment = []): bool
    {
        if ($alert->severity === Alert::SEVERITY_CRITICAL) {
            return true;
        }
        
        $highRiskVerdicts = [
            Alert::VERDICT_REAL_PANIC,
            Alert::VERDICT_CONFIRMED_VIOLATION,
            Alert::VERDICT_RISK_DETECTED,
        ];
        
        if (in_array($assessment['verdict'] ?? $alert->verdict, $highRiskVerdicts, true)) {
            return true;
        }
        
        $urgentEscalations = [Alert::RISK_CALL, Alert::RISK_EMERGENCY];
        if (in_array($assessment['risk_escalation'] ?? $alert->risk_escalation, $urgentEscalations, true)) {
            return true;
        }
        
        if (($assessment['verdict'] ?? $alert->verdict) === Alert::VERDICT_LIKELY_FALSE_POSITIVE) {
            return false;
        }
        
        if ($alert->severity === Alert::SEVERITY_INFO) {
            return false;
        }
        
        if ($alert->severity === Alert::SEVERITY_WARNING) {
            $likelihood = $assessment['likelihood'] ?? $alert->likelihood;
            return in_array($likelihood, [Alert::LIKELIHOOD_HIGH, Alert::LIKELIHOOD_MEDIUM], true);
        }
        
        return false;
    }

    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        $pgUniqueViolation = '23505';
        $mysqlDuplicateEntry = 1062;
        
        $errorCode = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;
        
        return $errorCode === $pgUniqueViolation || $driverCode === $mysqlDuplicateEntry;
    }
}
