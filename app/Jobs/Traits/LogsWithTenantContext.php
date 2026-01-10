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
 * Uso:
 *   use LogsWithTenantContext;
 * 
 *   public function handle(): void
 *   {
 *       $this->setLogContext($this->event->company);
 *       // Todos los Log:: ahora incluirán company_id y company_name
 *   }
 */
trait LogsWithTenantContext
{
    /**
     * Registra el contexto de empresa para todos los logs subsiguientes.
     * 
     * @param Company|null $company La empresa a registrar
     * @param int|null $companyId ID de empresa (alternativo si no tienes el modelo)
     * @param string|null $companyName Nombre de empresa (alternativo)
     */
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

        // También generar trace_id único para este job si no existe
        if (!app()->bound('trace_id')) {
            $traceId = 'job-' . dechex((int) (microtime(true) * 1000)) . '-' . substr(md5(uniqid('', true)), 0, 8);
            app()->instance('trace_id', $traceId);
        }
    }

    /**
     * Limpia el contexto de logs al finalizar el job.
     * Útil si procesas múltiples empresas en un solo job.
     */
    protected function clearLogContext(): void
    {
        if (app()->bound('log_company_id')) {
            app()->forgetInstance('log_company_id');
        }
        if (app()->bound('log_company_name')) {
            app()->forgetInstance('log_company_name');
        }
    }

    /**
     * Helper para obtener el trace_id actual.
     */
    protected function getTraceId(): string
    {
        if (app()->bound('trace_id')) {
            return app('trace_id');
        }
        return 'unknown';
    }
}
