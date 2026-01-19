<?php

namespace App\Console\Commands;

use App\Models\EventInvestigationStep;
use App\Models\EventRecommendedAction;
use App\Models\NotificationDecision as NotificationDecisionModel;
use App\Models\NotificationRecipient;
use App\Models\NotificationResult;
use App\Models\SamsaraEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Command to migrate existing data from JSON fields to normalized tables.
 * 
 * This command:
 * 1. Extracts data from ai_assessment JSON to normalized columns (verdict, likelihood, etc.)
 * 2. Extracts data from alert_context JSON to normalized columns (alert_kind, triage_notes, etc.)
 * 3. Populates event_recommended_actions table from ai_assessment.recommended_actions
 * 4. Populates event_investigation_steps table from alert_context.investigation_plan
 * 5. Populates notification_decisions table from notification_decision JSON
 * 6. Populates notification_results table from notification_execution.results
 * 
 * Run this command AFTER running the migrations that create the new tables.
 */
class MigrateEventDataToNormalizedSchema extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sam:migrate-normalized-data
                            {--dry-run : Run without making changes}
                            {--batch-size=100 : Number of events to process per batch}
                            {--event-id= : Process only a specific event ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate event data from JSON fields to normalized tables';

    /**
     * Counters for reporting.
     */
    protected int $processedCount = 0;
    protected int $updatedCount = 0;
    protected int $actionsCreated = 0;
    protected int $stepsCreated = 0;
    protected int $decisionsCreated = 0;
    protected int $resultsCreated = 0;
    protected int $errorCount = 0;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');
        $specificEventId = $this->option('event-id');

        $this->info('==============================================');
        $this->info('Starting Event Data Migration to Normalized Schema');
        $this->info('==============================================');
        
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Get events to process
        $query = SamsaraEvent::query()
            ->whereNotNull('ai_assessment')
            ->orWhereNotNull('alert_context')
            ->orWhereNotNull('notification_decision')
            ->orWhereNotNull('notification_execution');

        if ($specificEventId) {
            $query->where('id', $specificEventId);
        }

        $totalEvents = $query->count();
        $this->info("Found {$totalEvents} events with JSON data to migrate");

        if ($totalEvents === 0) {
            $this->info('No events to migrate.');
            return Command::SUCCESS;
        }

        // Process in batches
        $progressBar = $this->output->createProgressBar($totalEvents);
        $progressBar->start();

        $query->chunkById($batchSize, function ($events) use ($isDryRun, $progressBar) {
            foreach ($events as $event) {
                try {
                    $this->processEvent($event, $isDryRun);
                    $this->processedCount++;
                } catch (\Throwable $e) {
                    $this->errorCount++;
                    Log::error('MigrateNormalizedData: Error processing event', [
                        'event_id' => $event->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        // Report results
        $this->info('==============================================');
        $this->info('Migration Complete');
        $this->info('==============================================');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Events Processed', $this->processedCount],
                ['Events Updated', $this->updatedCount],
                ['Recommended Actions Created', $this->actionsCreated],
                ['Investigation Steps Created', $this->stepsCreated],
                ['Notification Decisions Created', $this->decisionsCreated],
                ['Notification Results Created', $this->resultsCreated],
                ['Errors', $this->errorCount],
            ]
        );

        if ($this->errorCount > 0) {
            $this->warn("Check logs for error details");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Process a single event.
     */
    protected function processEvent(SamsaraEvent $event, bool $isDryRun): void
    {
        $updateData = [];
        $hasChanges = false;

        // 1. Extract from ai_assessment JSON
        if ($event->ai_assessment && is_array($event->ai_assessment)) {
            $assessment = $event->ai_assessment;
            
            // Verdict
            if (isset($assessment['verdict']) && empty($event->verdict)) {
                $updateData['verdict'] = $this->normalizeVerdict($assessment['verdict']);
                $hasChanges = true;
            }
            
            // Likelihood
            if (isset($assessment['likelihood']) && empty($event->likelihood)) {
                $updateData['likelihood'] = $this->normalizeLikelihood($assessment['likelihood']);
                $hasChanges = true;
            }
            
            // Confidence
            if (isset($assessment['confidence']) && $event->confidence === null) {
                $updateData['confidence'] = min(1.0, max(0.0, (float) $assessment['confidence']));
                $hasChanges = true;
            }
            
            // Reasoning
            if (isset($assessment['reasoning']) && empty($event->reasoning)) {
                $updateData['reasoning'] = $assessment['reasoning'];
                $hasChanges = true;
            }
            
            // Monitoring reason
            if (isset($assessment['monitoring_reason']) && empty($event->monitoring_reason)) {
                $updateData['monitoring_reason'] = $assessment['monitoring_reason'];
            }
            
            // Supporting evidence
            if (isset($assessment['supporting_evidence']) && empty($event->supporting_evidence)) {
                $updateData['supporting_evidence'] = $assessment['supporting_evidence'];
                $hasChanges = true;
            }
            
            // Recommended actions (to new table)
            if (isset($assessment['recommended_actions']) && is_array($assessment['recommended_actions'])) {
                if (!$isDryRun) {
                    $this->createRecommendedActions($event->id, $assessment['recommended_actions']);
                }
                $this->actionsCreated += count($assessment['recommended_actions']);
            }
        }

        // 2. Extract from alert_context JSON
        if ($event->alert_context && is_array($event->alert_context)) {
            $context = $event->alert_context;
            
            // Alert kind
            if (isset($context['alert_kind']) && empty($event->alert_kind)) {
                $updateData['alert_kind'] = $this->normalizeAlertKind($context['alert_kind']);
                $hasChanges = true;
            }
            
            // Triage notes
            if (isset($context['triage_notes']) && empty($event->triage_notes)) {
                $updateData['triage_notes'] = $context['triage_notes'];
                $hasChanges = true;
            }
            
            // Investigation strategy
            if (isset($context['investigation_strategy']) && empty($event->investigation_strategy)) {
                $updateData['investigation_strategy'] = $context['investigation_strategy'];
                $hasChanges = true;
            }
            
            // Time window configuration
            if (isset($context['time_window'])) {
                $timeWindow = $context['time_window'];
                
                if (isset($timeWindow['correlation_window_minutes'])) {
                    $updateData['correlation_window_minutes'] = (int) $timeWindow['correlation_window_minutes'];
                }
                if (isset($timeWindow['media_window_seconds'])) {
                    $updateData['media_window_seconds'] = (int) $timeWindow['media_window_seconds'];
                }
                if (isset($timeWindow['safety_events_before_minutes'])) {
                    $updateData['safety_events_before_minutes'] = (int) $timeWindow['safety_events_before_minutes'];
                }
                if (isset($timeWindow['safety_events_after_minutes'])) {
                    $updateData['safety_events_after_minutes'] = (int) $timeWindow['safety_events_after_minutes'];
                }
                $hasChanges = true;
            }
            
            // Investigation plan (to new table)
            if (isset($context['investigation_plan']) && is_array($context['investigation_plan'])) {
                if (!$isDryRun) {
                    $this->createInvestigationSteps($event->id, $context['investigation_plan']);
                }
                $this->stepsCreated += count($context['investigation_plan']);
            }
        }

        // 3. Extract notification_decision to new table
        if ($event->notification_decision && is_array($event->notification_decision)) {
            $decision = $event->notification_decision;
            
            // Check if decision already exists
            $existingDecision = NotificationDecisionModel::where('samsara_event_id', $event->id)->first();
            
            if (!$existingDecision && !$isDryRun) {
                $this->createNotificationDecision($event->id, $decision);
            }
            $this->decisionsCreated++;
        }

        // 4. Extract notification_execution results to new table
        if ($event->notification_execution && is_array($event->notification_execution)) {
            $execution = $event->notification_execution;
            
            if (isset($execution['results']) && is_array($execution['results'])) {
                // Check if results already exist
                $existingResults = NotificationResult::where('samsara_event_id', $event->id)->count();
                
                if ($existingResults === 0 && !$isDryRun) {
                    $this->createNotificationResults($event->id, $execution['results']);
                }
                $this->resultsCreated += count($execution['results']);
            }
        }

        // 5. Store raw AI output for audit
        if (!$isDryRun && ($event->ai_assessment || $event->alert_context)) {
            $updateData['raw_ai_output'] = [
                'ai_assessment' => $event->ai_assessment,
                'alert_context' => $event->alert_context,
                'migrated_at' => now()->toIso8601String(),
            ];
            $hasChanges = true;
        }

        // Update event with normalized data
        if ($hasChanges && !$isDryRun && !empty($updateData)) {
            $event->update($updateData);
            $this->updatedCount++;
        }
    }

    /**
     * Create recommended actions in new table.
     */
    protected function createRecommendedActions(int $eventId, array $actions): void
    {
        // Check if already migrated
        if (EventRecommendedAction::where('samsara_event_id', $eventId)->exists()) {
            return;
        }
        
        foreach ($actions as $index => $action) {
            if (is_string($action)) {
                EventRecommendedAction::create([
                    'samsara_event_id' => $eventId,
                    'action_text' => $action,
                    'display_order' => $index,
                ]);
            }
        }
    }

    /**
     * Create investigation steps in new table.
     */
    protected function createInvestigationSteps(int $eventId, array $steps): void
    {
        // Check if already migrated
        if (EventInvestigationStep::where('samsara_event_id', $eventId)->exists()) {
            return;
        }
        
        foreach ($steps as $index => $step) {
            if (is_string($step)) {
                EventInvestigationStep::create([
                    'samsara_event_id' => $eventId,
                    'step_text' => $step,
                    'step_order' => $index,
                ]);
            }
        }
    }

    /**
     * Create notification decision in new table.
     */
    protected function createNotificationDecision(int $eventId, array $decision): void
    {
        $notificationDecision = NotificationDecisionModel::create([
            'samsara_event_id' => $eventId,
            'should_notify' => $decision['should_notify'] ?? false,
            'escalation_level' => $this->normalizeEscalationLevel($decision['escalation_level'] ?? 'none'),
            'message_text' => $decision['message_text'] ?? null,
            'call_script' => $decision['call_script'] ?? null,
            'reason' => $decision['reason'] ?? null,
        ]);

        // Create recipients
        if (isset($decision['recipients']) && is_array($decision['recipients'])) {
            foreach ($decision['recipients'] as $recipient) {
                NotificationRecipient::create([
                    'notification_decision_id' => $notificationDecision->id,
                    'recipient_type' => $this->normalizeRecipientType($recipient['recipient_type'] ?? 'operator'),
                    'phone' => $recipient['phone'] ?? null,
                    'whatsapp' => $recipient['whatsapp'] ?? null,
                    'priority' => $recipient['priority'] ?? 999,
                ]);
            }
        }
    }

    /**
     * Create notification results in new table.
     */
    protected function createNotificationResults(int $eventId, array $results): void
    {
        foreach ($results as $result) {
            NotificationResult::create([
                'samsara_event_id' => $eventId,
                'channel' => $this->normalizeChannel($result['channel'] ?? 'sms'),
                'recipient_type' => $result['recipient_type'] ?? null,
                'to_number' => $result['to'] ?? $result['to_number'] ?? 'unknown',
                'success' => $result['success'] ?? false,
                'error' => $result['error'] ?? null,
                'call_sid' => $result['call_sid'] ?? null,
                'message_sid' => $result['message_sid'] ?? null,
                'timestamp_utc' => isset($result['timestamp']) ? $result['timestamp'] : now(),
            ]);
        }
    }

    /**
     * Normalize verdict value to enum.
     */
    protected function normalizeVerdict(?string $verdict): ?string
    {
        if (!$verdict) {
            return null;
        }

        $validVerdicts = [
            'real_panic',
            'confirmed_violation',
            'needs_review',
            'uncertain',
            'likely_false_positive',
            'no_action_needed',
            'risk_detected',
        ];

        $normalized = strtolower(str_replace([' ', '-'], '_', $verdict));

        return in_array($normalized, $validVerdicts) ? $normalized : 'uncertain';
    }

    /**
     * Normalize likelihood value to enum.
     */
    protected function normalizeLikelihood(?string $likelihood): ?string
    {
        if (!$likelihood) {
            return null;
        }

        $normalized = strtolower($likelihood);

        if (in_array($normalized, ['high', 'medium', 'low'])) {
            return $normalized;
        }

        return 'medium';
    }

    /**
     * Normalize alert_kind value to enum.
     */
    protected function normalizeAlertKind(?string $kind): ?string
    {
        if (!$kind) {
            return null;
        }

        $validKinds = ['panic', 'safety', 'tampering', 'connectivity', 'unknown'];
        $normalized = strtolower(str_replace([' ', '-'], '_', $kind));

        return in_array($normalized, $validKinds) ? $normalized : 'unknown';
    }

    /**
     * Normalize escalation level.
     */
    protected function normalizeEscalationLevel(?string $level): string
    {
        $validLevels = ['critical', 'high', 'low', 'none'];
        $normalized = strtolower($level ?? 'none');

        return in_array($normalized, $validLevels) ? $normalized : 'none';
    }

    /**
     * Normalize recipient type.
     */
    protected function normalizeRecipientType(?string $type): string
    {
        $validTypes = ['operator', 'monitoring_team', 'supervisor', 'emergency', 'dispatch'];
        $normalized = strtolower(str_replace([' ', '-'], '_', $type ?? 'operator'));

        return in_array($normalized, $validTypes) ? $normalized : 'operator';
    }

    /**
     * Normalize channel.
     */
    protected function normalizeChannel(?string $channel): string
    {
        $validChannels = ['sms', 'whatsapp', 'call'];
        $normalized = strtolower($channel ?? 'sms');

        return in_array($normalized, $validChannels) ? $normalized : 'sms';
    }
}
