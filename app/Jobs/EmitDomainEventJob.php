<?php

namespace App\Jobs;

use App\Models\DomainEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmitDomainEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [5, 15];

    public function __construct(
        public int $companyId,
        public string $entityType,
        public string $entityId,
        public string $eventType,
        public array $payload = [],
        public string $actorType = 'system',
        public ?string $actorId = null,
        public ?string $traceparent = null,
        public ?string $correlationId = null,
    ) {
        $this->onQueue('domain-events');
    }

    public function handle(): void
    {
        DomainEvent::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->companyId,
            'occurred_at' => now(),
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'event_type' => $this->eventType,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'traceparent' => $this->traceparent,
            'correlation_id' => $this->correlationId,
            'schema_version' => 1,
            'payload' => $this->payload,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('EmitDomainEventJob failed', [
            'event_type' => $this->eventType,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'company_id' => $this->companyId,
            'error' => $exception->getMessage(),
        ]);
    }
}
