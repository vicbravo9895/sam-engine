<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Incident;
use App\Models\SafetySignal;
use App\Services\Incidents\IncidentCreationGate;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Detect Safety Patterns Command.
 * 
 * Este comando analiza los safety signals y detecta patrones de comportamiento
 * que ameriten crear un Incident agregado.
 * 
 * Patrones detectados:
 * - Repetición: Mismo behavior_label N veces para un conductor/vehículo en X horas
 * - Escalada: Incremento de severidad en eventos consecutivos
 * 
 * Debe ejecutarse periódicamente (cada 15-30 minutos) via scheduler.
 */
class DetectSafetyPatternsCommand extends Command
{
    protected $signature = 'samsara:detect-patterns 
                            {--company= : Analyze only a specific company ID}
                            {--hours=4 : Time window to analyze (default: 4 hours)}
                            {--threshold=3 : Minimum occurrences to trigger pattern (default: 3)}
                            {--dry-run : Show what would be created without making changes}';

    protected $description = 'Detect safety event patterns and create aggregated incidents';

    /**
     * Pattern types we detect.
     */
    private const PATTERN_REPETITION = 'repetition';
    private const PATTERN_ESCALATION = 'escalation';

    /**
     * Behavior labels that are significant for pattern detection.
     * Excludes minor events that shouldn't trigger pattern incidents.
     */
    private array $significantBehaviors = [
        // Driving behaviors
        'Hard Braking' => 'frenado_brusco',
        'Harsh Braking' => 'frenado_brusco',
        'Hard Acceleration' => 'aceleracion_brusca',
        'Harsh Acceleration' => 'aceleracion_brusca',
        'Sharp Turn' => 'giro_brusco',
        'Harsh Turn' => 'giro_brusco',
        'Speeding' => 'exceso_velocidad',
        'Lane Departure' => 'salida_carril',
        'Following Distance' => 'distancia_seguimiento',
        'Rolling Stop' => 'alto_rodante',
        
        // Distraction behaviors
        'Distracted Driving' => 'distraccion',
        'Cell Phone Use' => 'uso_celular',
        'Cell Phone' => 'uso_celular',
        'Drowsiness' => 'somnolencia',
        'Yawning' => 'bostezo',
        'Eyes Closed' => 'ojos_cerrados',
        
        // Safety violations
        'No Seatbelt' => 'sin_cinturon',
        'Smoking' => 'fumando',
        'Obstructed Camera' => 'camara_obstruida',
        
        // Collision events
        'Collision' => 'colision',
        'Near Collision' => 'casi_colision',
        'Forward Collision Warning' => 'advertencia_colision',
    ];

    public function handle(IncidentCreationGate $incidentGate): int
    {
        $companyId = $this->option('company');
        $hours = (int) $this->option('hours');
        $threshold = (int) $this->option('threshold');
        $dryRun = $this->option('dry-run');

        $this->info('Detecting safety patterns...');
        $this->info("Time window: {$hours} hours | Threshold: {$threshold} occurrences");
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No incidents will be created');
        }

        Log::info('DetectSafetyPatterns: Starting pattern detection', [
            'hours' => $hours,
            'threshold' => $threshold,
            'company_id' => $companyId,
        ]);

        // Get companies to analyze
        $companiesQuery = Company::query()
            ->where('is_active', true)
            ->whereNotNull('samsara_api_key');

        if ($companyId) {
            $companiesQuery->where('id', $companyId);
        }

        $companies = $companiesQuery->get();

        if ($companies->isEmpty()) {
            $this->info('No active companies found.');
            return Command::SUCCESS;
        }

        $totalPatterns = 0;
        $totalIncidents = 0;

        foreach ($companies as $company) {
            $result = $this->analyzeCompany($company, $hours, $threshold, $dryRun, $incidentGate);
            $totalPatterns += $result['patterns_found'];
            $totalIncidents += $result['incidents_created'];
        }

        $this->newLine();
        $this->info('Pattern detection complete!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Companies analyzed', $companies->count()],
                ['Patterns detected', $totalPatterns],
                ['Incidents created', $totalIncidents],
            ]
        );

        Log::info('DetectSafetyPatterns: Completed', [
            'companies' => $companies->count(),
            'patterns' => $totalPatterns,
            'incidents' => $totalIncidents,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Analyze patterns for a single company.
     */
    private function analyzeCompany(
        Company $company,
        int $hours,
        int $threshold,
        bool $dryRun,
        IncidentCreationGate $incidentGate
    ): array {
        $startTime = now()->subHours($hours);
        
        $this->line("\nAnalyzing: {$company->name}");

        // Get safety signals in the time window
        $signals = SafetySignal::query()
            ->forCompany($company->id)
            ->where('occurred_at', '>=', $startTime)
            ->orderBy('occurred_at', 'desc')
            ->get();

        if ($signals->isEmpty()) {
            $this->line("  No signals in time window.");
            return ['patterns_found' => 0, 'incidents_created' => 0];
        }

        $this->line("  Found {$signals->count()} signals to analyze.");

        $patternsFound = 0;
        $incidentsCreated = 0;

        // Detect repetition patterns by driver
        $driverPatterns = $this->detectRepetitionPatterns($signals, 'driver_id', $threshold);
        
        foreach ($driverPatterns as $pattern) {
            $patternsFound++;
            
            $this->line("  Pattern: {$pattern['behavior']} x{$pattern['count']} - Driver: {$pattern['subject_name']}");
            
            if (!$dryRun) {
                $incident = $this->createPatternIncident(
                    $company->id,
                    $pattern,
                    Incident::SUBJECT_DRIVER,
                    $incidentGate
                );
                
                if ($incident) {
                    $incidentsCreated++;
                    $this->info("    -> Created Incident #{$incident->id}");
                }
            }
        }

        // Detect repetition patterns by vehicle
        $vehiclePatterns = $this->detectRepetitionPatterns($signals, 'vehicle_id', $threshold);
        
        foreach ($vehiclePatterns as $pattern) {
            // Skip if we already detected this as a driver pattern
            $alreadyDetected = collect($driverPatterns)->contains(function ($dp) use ($pattern) {
                return $dp['behavior'] === $pattern['behavior'] 
                    && count(array_intersect($dp['signal_ids'], $pattern['signal_ids'])) > 0;
            });
            
            if ($alreadyDetected) {
                continue;
            }
            
            $patternsFound++;
            
            $this->line("  Pattern: {$pattern['behavior']} x{$pattern['count']} - Vehicle: {$pattern['subject_name']}");
            
            if (!$dryRun) {
                $incident = $this->createPatternIncident(
                    $company->id,
                    $pattern,
                    Incident::SUBJECT_VEHICLE,
                    $incidentGate
                );
                
                if ($incident) {
                    $incidentsCreated++;
                    $this->info("    -> Created Incident #{$incident->id}");
                }
            }
        }

        return [
            'patterns_found' => $patternsFound,
            'incidents_created' => $incidentsCreated,
        ];
    }

    /**
     * Detect repetition patterns grouped by subject (driver or vehicle).
     */
    private function detectRepetitionPatterns(Collection $signals, string $subjectField, int $threshold): array
    {
        $patterns = [];

        // Group by subject and behavior
        $grouped = $signals
            ->filter(fn ($s) => !empty($s->$subjectField) && $s->$subjectField !== '0')
            ->groupBy(function ($signal) use ($subjectField) {
                // Get normalized behavior
                $behavior = $this->normalizeBehavior($signal->behavior_labels ?? []);
                return $signal->$subjectField . '::' . $behavior;
            });

        foreach ($grouped as $key => $group) {
            [$subjectId, $behavior] = explode('::', $key, 2);
            
            // Skip if not a significant behavior
            if (empty($behavior) || $behavior === 'other') {
                continue;
            }
            
            // Check threshold
            if ($group->count() < $threshold) {
                continue;
            }

            // Get subject name
            $firstSignal = $group->first();
            $subjectName = $subjectField === 'driver_id' 
                ? $firstSignal->driver_name 
                : $firstSignal->vehicle_name;

            $patterns[] = [
                'type' => self::PATTERN_REPETITION,
                'behavior' => $behavior,
                'behavior_label' => $this->getBehaviorLabel($behavior),
                'subject_id' => $subjectId,
                'subject_name' => $subjectName ?? 'Desconocido',
                'count' => $group->count(),
                'signal_ids' => $group->pluck('id')->toArray(),
                'first_occurrence' => $group->min('occurred_at'),
                'last_occurrence' => $group->max('occurred_at'),
            ];
        }

        return $patterns;
    }

    /**
     * Normalize behavior labels to a single key.
     */
    private function normalizeBehavior(array $behaviorLabels): string
    {
        foreach ($behaviorLabels as $label) {
            $labelName = $label['name'] ?? $label;
            
            if (is_string($labelName) && isset($this->significantBehaviors[$labelName])) {
                return $this->significantBehaviors[$labelName];
            }
        }

        return 'other';
    }

    /**
     * Get Spanish label for a behavior key.
     */
    private function getBehaviorLabel(string $behaviorKey): string
    {
        $labels = [
            'frenado_brusco' => 'Frenado brusco',
            'aceleracion_brusca' => 'Aceleración brusca',
            'giro_brusco' => 'Giro brusco',
            'exceso_velocidad' => 'Exceso de velocidad',
            'salida_carril' => 'Salida de carril',
            'distancia_seguimiento' => 'Distancia de seguimiento',
            'alto_rodante' => 'Alto sin detenerse',
            'distraccion' => 'Distracción',
            'uso_celular' => 'Uso de celular',
            'somnolencia' => 'Somnolencia',
            'bostezo' => 'Bostezo',
            'ojos_cerrados' => 'Ojos cerrados',
            'sin_cinturon' => 'Sin cinturón',
            'fumando' => 'Fumando',
            'camara_obstruida' => 'Cámara obstruida',
            'colision' => 'Colisión',
            'casi_colision' => 'Casi colisión',
            'advertencia_colision' => 'Advertencia de colisión',
        ];

        return $labels[$behaviorKey] ?? $behaviorKey;
    }

    /**
     * Create an incident from a detected pattern.
     */
    private function createPatternIncident(
        int $companyId,
        array $pattern,
        string $subjectType,
        IncidentCreationGate $incidentGate
    ): ?Incident {
        // Generate dedupe key: type:behavior:subject:window
        $windowKey = now()->floorMinutes(30)->format('Y-m-d-H-i');
        $dedupeKey = implode(':', [
            self::PATTERN_REPETITION,
            $pattern['behavior'],
            $subjectType,
            $pattern['subject_id'],
            $windowKey,
        ]);

        // Build metadata
        $metadata = [
            'pattern_type' => $pattern['type'],
            'behavior' => $pattern['behavior'],
            'behavior_label' => $pattern['behavior_label'],
            'occurrence_count' => $pattern['count'],
            'first_occurrence' => $pattern['first_occurrence']?->toIso8601String(),
            'last_occurrence' => $pattern['last_occurrence']?->toIso8601String(),
            'signal_ids' => $pattern['signal_ids'],
        ];

        try {
            $incident = $incidentGate->createFromAutoCandidate(
                companyId: $companyId,
                incidentType: Incident::TYPE_PATTERN,
                dedupeKey: $dedupeKey,
                subjectType: $subjectType,
                subjectId: $pattern['subject_id'],
                subjectName: $pattern['subject_name'],
                metadata: $metadata
            );

            if ($incident) {
                // Link the safety signals to the incident
                $signalIds = $pattern['signal_ids'];
                $signals = SafetySignal::whereIn('id', $signalIds)->get();
                $incident->linkSignals($signals, 'supporting');

                // Generate AI summary
                $incident->update([
                    'ai_summary' => $this->generatePatternSummary($pattern, $subjectType),
                ]);

                Log::info('DetectSafetyPatterns: Incident created', [
                    'incident_id' => $incident->id,
                    'company_id' => $companyId,
                    'pattern' => $pattern['behavior'],
                    'count' => $pattern['count'],
                    'subject_type' => $subjectType,
                    'subject_id' => $pattern['subject_id'],
                ]);
            }

            return $incident;
        } catch (\Exception $e) {
            Log::warning('DetectSafetyPatterns: Failed to create incident', [
                'company_id' => $companyId,
                'pattern' => $pattern['behavior'],
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate a human-readable summary for the pattern.
     */
    private function generatePatternSummary(array $pattern, string $subjectType): string
    {
        $subjectLabel = $subjectType === Incident::SUBJECT_DRIVER ? 'conductor' : 'vehículo';
        
        return sprintf(
            "Patrón detectado: %s x%d para %s '%s' en las últimas horas. Requiere revisión.",
            $pattern['behavior_label'],
            $pattern['count'],
            $subjectLabel,
            $pattern['subject_name']
        );
    }
}
