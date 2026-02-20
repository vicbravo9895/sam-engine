<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\AlertSource;
use App\Models\Signal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertSource> */
class AlertSourceFactory extends Factory
{
    protected $model = AlertSource::class;

    public function definition(): array
    {
        return [
            'alert_id' => Alert::factory(),
            'signal_id' => Signal::factory(),
            'role' => 'primary',
            'relevance' => 1.0,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['role' => 'primary', 'relevance' => 1.0]);
    }

    public function correlated(): static
    {
        return $this->state(fn () => [
            'role' => 'correlated',
            'relevance' => fake()->randomFloat(2, 0.3, 0.9),
        ]);
    }
}
