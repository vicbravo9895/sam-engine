<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\UsageDailySummary;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsageDailySummary> */
class UsageDailySummaryFactory extends Factory
{
    protected $model = UsageDailySummary::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'date' => now()->subDay()->toDateString(),
            'meter' => 'alerts_processed',
            'total_qty' => fake()->numberBetween(1, 100),
            'computed_at' => now(),
        ];
    }
}
