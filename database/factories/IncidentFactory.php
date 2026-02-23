<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Incident> */
class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'incident_type' => Incident::TYPE_SAFETY_VIOLATION,
            'priority' => Incident::PRIORITY_P3,
            'severity' => Incident::SEVERITY_WARNING,
            'status' => Incident::STATUS_OPEN,
            'subject_type' => Incident::SUBJECT_DRIVER,
            'subject_id' => fake()->uuid(),
            'subject_name' => fake()->name(),
            'source' => Incident::SOURCE_WEBHOOK,
            'samsara_event_id' => null,
            'dedupe_key' => null,
            'ai_summary' => fake()->sentence(),
            'ai_assessment' => null,
            'metadata' => null,
            'detected_at' => now()->subMinutes(fake()->numberBetween(1, 120)),
            'resolved_at' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => Incident::STATUS_OPEN]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => Incident::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);
    }

    public function falsePositive(): static
    {
        return $this->state(fn () => [
            'status' => Incident::STATUS_FALSE_POSITIVE,
            'resolved_at' => now(),
        ]);
    }

    public function investigating(): static
    {
        return $this->state(fn () => ['status' => Incident::STATUS_INVESTIGATING]);
    }

    public function pendingAction(): static
    {
        return $this->state(fn () => ['status' => Incident::STATUS_PENDING_ACTION]);
    }

    public function p1(): static
    {
        return $this->state(fn () => ['priority' => Incident::PRIORITY_P1]);
    }

    public function p2(): static
    {
        return $this->state(fn () => ['priority' => Incident::PRIORITY_P2]);
    }

    public function p3(): static
    {
        return $this->state(fn () => ['priority' => Incident::PRIORITY_P3]);
    }

    public function p4(): static
    {
        return $this->state(fn () => ['priority' => Incident::PRIORITY_P4]);
    }
}
