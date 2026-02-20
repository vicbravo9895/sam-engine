<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\AlertMetrics;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertMetrics> */
class AlertMetricsFactory extends Factory
{
    protected $model = AlertMetrics::class;

    public function definition(): array
    {
        $started = now()->subSeconds(fake()->numberBetween(5, 30));
        $finished = $started->copy()->addSeconds(fake()->numberBetween(2, 15));

        return [
            'alert_id' => Alert::factory(),
            'ai_started_at' => $started,
            'ai_finished_at' => $finished,
            'pipeline_latency_ms' => $started->diffInMilliseconds($finished),
        ];
    }

    public function withLatency(int $ms = 5000): static
    {
        return $this->state(fn () => [
            'pipeline_latency_ms' => $ms,
        ]);
    }

    public function withTokens(int $tokens = 1500, float $cost = 0.0045): static
    {
        return $this->state(fn () => [
            'ai_tokens' => $tokens,
            'ai_cost_estimate' => $cost,
        ]);
    }
}
