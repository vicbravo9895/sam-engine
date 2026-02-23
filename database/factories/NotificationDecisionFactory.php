<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\NotificationDecision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationDecision> */
class NotificationDecisionFactory extends Factory
{
    protected $model = NotificationDecision::class;

    public function definition(): array
    {
        return [
            'alert_id' => Alert::factory(),
            'should_notify' => true,
            'escalation_level' => 'critical',
            'message_text' => fake()->sentence(),
            'reason' => 'Critical safety event requires notification.',
            'created_at' => now(),
        ];
    }

    public function shouldNotify(): static
    {
        return $this->state(fn () => ['should_notify' => true]);
    }

    public function shouldNotNotify(): static
    {
        return $this->state(fn () => [
            'should_notify' => false,
            'reason' => 'Event does not require notification.',
        ]);
    }
}
