<?php

use App\Models\EventInvestigationStep;
use App\Models\EventRecommendedAction;
use App\Models\SamsaraEvent;
use Illuminate\Database\Migrations\Migration;

/**
 * T3 — Fuente de verdad única.
 *
 * Pobla event_recommended_actions y event_investigation_steps desde JSON legacy
 * (samsara_events.recommended_actions, ai_assessment.recommended_actions,
 * alert_context.investigation_plan) una sola vez. A partir de aquí la UI lee solo tablas.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->backfillRecommendedActions();
        $this->backfillInvestigationSteps();
    }

    /**
     * Poblar event_recommended_actions desde recommended_actions (columna) o ai_assessment.recommended_actions.
     */
    private function backfillRecommendedActions(): void
    {
        SamsaraEvent::query()
            ->whereDoesntHave('recommendedActions')
            ->chunkById(200, function ($events) {
                foreach ($events as $event) {
                    $actions = $this->getLegacyRecommendedActions($event);
                    if ($actions === []) {
                        continue;
                    }
                    EventRecommendedAction::replaceForEvent($event->id, $actions);
                }
            });
    }

    /**
     * Obtener recommended_actions desde fuentes legacy (solo para backfill).
     */
    private function getLegacyRecommendedActions(SamsaraEvent $event): array
    {
        $fromColumn = $event->getRawOriginal('recommended_actions');
        if ($fromColumn !== null && $fromColumn !== '') {
            $decoded = json_decode($fromColumn, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, 'is_string'));
            }
        }
        $assessment = $event->ai_assessment;
        if (is_array($assessment) && isset($assessment['recommended_actions']) && is_array($assessment['recommended_actions'])) {
            return array_values(array_filter($assessment['recommended_actions'], 'is_string'));
        }
        return [];
    }

    /**
     * Poblar event_investigation_steps desde alert_context.investigation_plan.
     */
    private function backfillInvestigationSteps(): void
    {
        SamsaraEvent::query()
            ->whereDoesntHave('investigationSteps')
            ->whereNotNull('alert_context')
            ->chunkById(200, function ($events) {
                foreach ($events as $event) {
                    $plan = $event->alert_context['investigation_plan'] ?? null;
                    if (!is_array($plan)) {
                        continue;
                    }
                    $steps = array_values(array_filter($plan, 'is_string'));
                    if ($steps === []) {
                        continue;
                    }
                    EventInvestigationStep::replaceForEvent($event->id, $steps);
                }
            });
    }

    /**
     * Reverse the migrations.
     * Backfill is additive; we do not delete data on down (would require business logic).
     */
    public function down(): void
    {
        // No-op: normalized tables keep data.
    }
};
