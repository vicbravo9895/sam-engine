<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'thread_id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'title' => fake()->sentence(4),
            'total_input_tokens' => 0,
            'total_output_tokens' => 0,
            'total_tokens' => 0,
        ];
    }

    public function withContext(int $alertId = null): static
    {
        return $this->state(fn () => [
            'context_event_id' => $alertId ?? fake()->randomNumber(5),
            'context_payload' => ['alert_kind' => 'safety', 'severity' => 'warning'],
        ]);
    }
}
