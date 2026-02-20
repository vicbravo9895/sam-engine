<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\AlertComment;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertComment> */
class AlertCommentFactory extends Factory
{
    protected $model = AlertComment::class;

    public function definition(): array
    {
        return [
            'alert_id' => Alert::factory(),
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'content' => fake()->paragraph(),
        ];
    }
}
