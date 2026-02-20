<?php

namespace App\Jobs\Traits;

use App\Models\Company;

/**
 * Trait para registrar el contexto de empresa en los logs.
 * 
 * Este trait permite que los Jobs registren automáticamente el company_id
 * y company_name en todos los logs que generen, facilitando el filtrado
 * en Grafana por empresa.
 *
 * También genera un W3C traceparent si no existe en el container,
 * garantizando trazabilidad distribuida end-to-end.
 */
trait LogsWithTenantContext
{
    protected function setLogContext(
        ?Company $company = null,
        ?int $companyId = null,
        ?string $companyName = null
    ): void {
        if ($company) {
            app()->instance('log_company_id', $company->id);
            app()->instance('log_company_name', $company->name);
        } else {
            if ($companyId) {
                app()->instance('log_company_id', $companyId);
            }
            if ($companyName) {
                app()->instance('log_company_name', $companyName);
            }
        }

        if (!app()->bound('traceparent')) {
            $traceId = bin2hex(random_bytes(16));
            $spanId = bin2hex(random_bytes(8));
            $traceparent = "00-{$traceId}-{$spanId}-01";

            app()->instance('traceparent', $traceparent);
            app()->instance('trace_id', $traceId);
        }
    }

    protected function clearLogContext(): void
    {
        if (app()->bound('log_company_id')) {
            app()->forgetInstance('log_company_id');
        }
        if (app()->bound('log_company_name')) {
            app()->forgetInstance('log_company_name');
        }
    }

    protected function getTraceId(): string
    {
        if (app()->bound('trace_id')) {
            return app('trace_id');
        }
        return 'unknown';
    }

    protected function getTraceparent(): string
    {
        if (app()->bound('traceparent')) {
            return app('traceparent');
        }
        return 'unknown';
    }
}
