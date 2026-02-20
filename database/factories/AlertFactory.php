<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\Company;
use App\Models\Signal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Alert> */
class AlertFactory extends Factory
{
    protected $model = Alert::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'signal_id' => Signal::factory(),
            'ai_status' => Alert::STATUS_PENDING,
            'severity' => fake()->randomElement(['info', 'warning', 'critical']),
            'human_status' => Alert::HUMAN_STATUS_PENDING,
            'occurred_at' => now()->subMinutes(fake()->numberBetween(1, 60)),
            'event_description' => fake()->sentence(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['ai_status' => Alert::STATUS_PENDING]);
    }

    public function processing(): static
    {
        return $this->state(fn () => ['ai_status' => Alert::STATUS_PROCESSING]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'ai_status' => Alert::STATUS_COMPLETED,
            'verdict' => Alert::VERDICT_CONFIRMED_VIOLATION,
            'likelihood' => Alert::LIKELIHOOD_HIGH,
            'confidence' => 0.85,
            'reasoning' => 'Test reasoning for completed alert.',
            'ai_message' => 'Alert processed successfully.',
            'alert_kind' => Alert::ALERT_KIND_SAFETY,
            'risk_escalation' => Alert::RISK_WARN,
        ]);
    }

    public function investigating(): static
    {
        return $this->state(fn () => [
            'ai_status' => Alert::STATUS_INVESTIGATING,
            'verdict' => Alert::VERDICT_UNCERTAIN,
            'likelihood' => Alert::LIKELIHOOD_MEDIUM,
            'confidence' => 0.55,
            'reasoning' => 'Requires further monitoring.',
            'ai_message' => 'Monitoring in progress.',
            'alert_kind' => Alert::ALERT_KIND_SAFETY,
            'risk_escalation' => Alert::RISK_MONITOR,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'ai_status' => Alert::STATUS_FAILED,
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn () => [
            'severity' => Alert::SEVERITY_CRITICAL,
            'risk_escalation' => Alert::RISK_CALL,
        ]);
    }

    public function panicButton(): static
    {
        return $this->state(fn () => [
            'severity' => Alert::SEVERITY_CRITICAL,
            'alert_kind' => Alert::ALERT_KIND_PANIC,
            'verdict' => Alert::VERDICT_REAL_PANIC,
            'risk_escalation' => Alert::RISK_EMERGENCY,
            'event_description' => 'Botón de pánico activado',
        ]);
    }

    public function withAttention(): static
    {
        return $this->state(fn () => [
            'attention_state' => Alert::ATTENTION_NEEDS_ATTENTION,
            'ack_status' => Alert::ACK_PENDING,
            'ack_due_at' => now()->addMinutes(15),
            'resolve_due_at' => now()->addHours(4),
            'next_escalation_at' => now()->addMinutes(10),
            'escalation_level' => 0,
            'escalation_count' => 0,
        ]);
    }

    public function withNotification(): static
    {
        return $this->state(fn () => [
            'notification_status' => 'sent',
            'notification_channels' => ['sms', 'whatsapp'],
            'notification_sent_at' => now(),
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }
}
