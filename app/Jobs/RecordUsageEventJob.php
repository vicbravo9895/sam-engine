<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\UsageEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

class RecordUsageEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 15];

    public function __construct(
        public readonly int $companyId,
        public readonly string $meter,
        public readonly float|int $qty,
        public readonly string $idempotencyKey,
        public readonly ?array $dimensions = null,
        public readonly ?string $occurredAt = null,
    ) {
        $this->onQueue('metering');
    }

    public function handle(): void
    {
        $company = Company::find($this->companyId);

        if (!$company || !Feature::for($company)->active('metering-v1')) {
            return;
        }

        UsageEvent::record(
            companyId: $this->companyId,
            meter: $this->meter,
            qty: $this->qty,
            idempotencyKey: $this->idempotencyKey,
            dimensions: $this->dimensions,
            occurredAt: $this->occurredAt ? new \DateTimeImmutable($this->occurredAt) : null,
        );
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RecordUsageEventJob failed', [
            'company_id' => $this->companyId,
            'meter' => $this->meter,
            'idempotency_key' => $this->idempotencyKey,
            'error' => $e->getMessage(),
        ]);
    }
}
