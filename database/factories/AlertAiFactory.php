<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\AlertAi;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertAi> */
class AlertAiFactory extends Factory
{
    protected $model = AlertAi::class;

    public function definition(): array
    {
        return [
            'alert_id' => Alert::factory(),
            'investigation_count' => 0,
            'next_check_minutes' => null,
        ];
    }

    public function withAssessment(): static
    {
        return $this->state(fn () => [
            'ai_assessment' => [
                'verdict' => 'confirmed_violation',
                'likelihood' => 'high',
                'confidence' => 0.92,
                'reasoning' => 'Clear evidence of safety violation.',
                'recommended_actions' => ['Notify supervisor', 'Review dashcam footage'],
                'risk_escalation' => 'warn',
            ],
            'alert_context' => [
                'alert_kind' => 'safety',
                'triage_notes' => 'Safety event with harsh braking.',
                'investigation_strategy' => 'Check vehicle stats and camera.',
                'proactive_flag' => false,
            ],
            'ai_actions' => [
                'total_tokens' => 1500,
                'cost_estimate' => 0.0045,
                'agents_executed' => ['triage', 'investigator', 'final', 'notification'],
            ],
        ]);
    }

    public function withMonitoring(): static
    {
        return $this->state(fn () => [
            'investigation_count' => 1,
            'last_investigation_at' => now(),
            'next_check_minutes' => 15,
            'monitoring_reason' => 'Requires continued observation.',
            'ai_assessment' => [
                'verdict' => 'uncertain',
                'likelihood' => 'medium',
                'confidence' => 0.55,
                'requires_monitoring' => true,
                'monitoring_reason' => 'Insufficient data for conclusion.',
            ],
        ]);
    }

    public function withHistory(): static
    {
        return $this->state(fn () => [
            'investigation_count' => 3,
            'last_investigation_at' => now()->subMinutes(15),
            'next_check_minutes' => 15,
            'investigation_history' => [
                [
                    'investigation_number' => 1,
                    'timestamp' => now()->subHour()->toIso8601String(),
                    'reason' => 'Initial monitoring',
                ],
                [
                    'investigation_number' => 2,
                    'timestamp' => now()->subMinutes(30)->toIso8601String(),
                    'reason' => 'Continued observation',
                ],
                [
                    'investigation_number' => 3,
                    'timestamp' => now()->subMinutes(15)->toIso8601String(),
                    'reason' => 'Final check',
                ],
            ],
        ]);
    }
}
