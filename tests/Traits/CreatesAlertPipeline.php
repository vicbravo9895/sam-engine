<?php

namespace Tests\Traits;

use App\Models\Alert;
use App\Models\AlertAi;
use App\Models\AlertMetrics;
use App\Models\AlertSource;
use App\Models\Company;
use App\Models\Signal;

trait CreatesAlertPipeline
{
    protected function createFullAlert(
        Company $company,
        array $signalOverrides = [],
        array $alertOverrides = [],
        array $aiOverrides = [],
    ): array {
        $signal = Signal::factory()->create(array_merge(
            ['company_id' => $company->id],
            $signalOverrides,
        ));

        $alert = Alert::factory()->create(array_merge(
            ['company_id' => $company->id, 'signal_id' => $signal->id],
            $alertOverrides,
        ));

        AlertSource::factory()->primary()->create([
            'alert_id' => $alert->id,
            'signal_id' => $signal->id,
        ]);

        $ai = AlertAi::factory()->create(array_merge(
            ['alert_id' => $alert->id],
            $aiOverrides,
        ));

        $metrics = AlertMetrics::factory()->create([
            'alert_id' => $alert->id,
        ]);

        return compact('signal', 'alert', 'ai', 'metrics');
    }

    protected function createPendingAlert(Company $company): array
    {
        return $this->createFullAlert($company, [], [
            'ai_status' => Alert::STATUS_PENDING,
        ]);
    }

    protected function createCompletedAlert(Company $company): array
    {
        return $this->createFullAlert(
            $company,
            [],
            [
                'ai_status' => Alert::STATUS_COMPLETED,
                'verdict' => Alert::VERDICT_CONFIRMED_VIOLATION,
                'likelihood' => Alert::LIKELIHOOD_HIGH,
                'confidence' => 0.92,
                'ai_message' => 'Alert processed.',
                'alert_kind' => Alert::ALERT_KIND_SAFETY,
                'risk_escalation' => Alert::RISK_WARN,
            ],
            ['ai_assessment' => ['verdict' => 'confirmed_violation']],
        );
    }

    protected function createInvestigatingAlert(Company $company): array
    {
        return $this->createFullAlert(
            $company,
            [],
            [
                'ai_status' => Alert::STATUS_INVESTIGATING,
                'verdict' => Alert::VERDICT_UNCERTAIN,
                'likelihood' => Alert::LIKELIHOOD_MEDIUM,
            ],
            [
                'investigation_count' => 1,
                'last_investigation_at' => now(),
                'next_check_minutes' => 15,
            ],
        );
    }

    protected function createCriticalPanicAlert(Company $company): array
    {
        return $this->createFullAlert(
            $company,
            ['event_type' => 'AlertIncident', 'event_description' => 'Botón de pánico'],
            [
                'severity' => Alert::SEVERITY_CRITICAL,
                'alert_kind' => Alert::ALERT_KIND_PANIC,
                'verdict' => Alert::VERDICT_REAL_PANIC,
                'risk_escalation' => Alert::RISK_EMERGENCY,
                'ai_status' => Alert::STATUS_COMPLETED,
                'ai_message' => 'Panic alert confirmed.',
                'event_description' => 'Botón de pánico',
            ],
        );
    }
}
