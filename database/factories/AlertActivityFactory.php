<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\AlertActivity;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertActivity> */
class AlertActivityFactory extends Factory
{
    protected $model = AlertActivity::class;

    public function definition(): array
    {
        return [
            'alert_id' => Alert::factory(),
            'company_id' => Company::factory(),
            'user_id' => null,
            'action' => 'status_changed',
            'metadata' => ['old_status' => 'pending', 'new_status' => 'reviewed'],
            'created_at' => now(),
        ];
    }

    public function byUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function humanStatusChanged(): static
    {
        return $this->state(fn () => [
            'action' => 'human_status_changed',
            'metadata' => ['old_status' => 'pending', 'new_status' => 'reviewed'],
        ]);
    }

    public function commentAdded(): static
    {
        return $this->state(fn () => [
            'action' => 'comment_added',
            'metadata' => ['comment_id' => fake()->randomNumber()],
        ]);
    }

    public function acknowledged(): static
    {
        return $this->state(fn () => [
            'action' => 'acknowledged',
            'metadata' => [],
        ]);
    }

    public function escalated(): static
    {
        return $this->state(fn () => [
            'action' => 'escalated',
            'metadata' => ['level' => 1, 'reason' => 'SLA overdue'],
        ]);
    }
}
