<?php

namespace App\Services;

use App\Jobs\EmitDomainEventJob;
use App\Models\Company;
use Laravel\Pennant\Feature;

class DomainEventEmitter
{
    /**
     * Emit a domain event asynchronously via the domain-events queue.
     *
     * The event is only dispatched when the `ledger-v1` feature flag
     * is active for the given company.
     */
    public static function emit(
        int $companyId,
        string $entityType,
        string $entityId,
        string $eventType,
        array $payload = [],
        string $actorType = 'system',
        ?string $actorId = null,
        ?string $traceparent = null,
        ?string $correlationId = null,
    ): void {
        $company = Company::find($companyId);

        if (!$company || !Feature::for($company)->active('ledger-v1')) {
            return;
        }

        $traceparent ??= app()->bound('traceparent') ? app('traceparent') : null;

        EmitDomainEventJob::dispatch(
            companyId: $companyId,
            entityType: $entityType,
            entityId: $entityId,
            eventType: $eventType,
            payload: $payload,
            actorType: $actorType,
            actorId: $actorId,
            traceparent: $traceparent,
            correlationId: $correlationId,
        );
    }
}
