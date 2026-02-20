<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tag> */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'samsara_id' => (string) fake()->numerify('##########'),
            'name' => fake()->words(2, true),
        ];
    }

    public function withParent(string $parentSamsaraId): static
    {
        return $this->state(fn () => [
            'parent_tag_id' => $parentSamsaraId,
        ]);
    }
}
