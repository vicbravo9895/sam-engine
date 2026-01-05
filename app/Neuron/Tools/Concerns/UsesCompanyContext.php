<?php

declare(strict_types=1);

namespace App\Neuron\Tools\Concerns;

use App\Neuron\CompanyContext;
use App\Samsara\Client\SamsaraClient;

/**
 * Trait for tools that need company context.
 * 
 * Provides easy access to the current company context for
 * multi-tenant data isolation and Samsara API access.
 */
trait UsesCompanyContext
{
    /**
     * Get the current company context.
     * 
     * @throws \RuntimeException If no company context is set
     */
    protected function getCompanyContext(): CompanyContext
    {
        $context = CompanyContext::current();

        if (!$context) {
            throw new \RuntimeException(
                'No hay contexto de empresa disponible. ' .
                'Asegúrate de que el usuario esté autenticado y asociado a una empresa.'
            );
        }

        return $context;
    }

    /**
     * Get the current company ID.
     */
    protected function getCompanyId(): int
    {
        return $this->getCompanyContext()->getCompanyId();
    }

    /**
     * Create a Samsara client for the current company.
     */
    protected function createSamsaraClient(): SamsaraClient
    {
        return $this->getCompanyContext()->createSamsaraClient();
    }

    /**
     * Check if the current company has Samsara access.
     */
    protected function hasSamsaraAccess(): bool
    {
        return $this->getCompanyContext()->hasSamsaraAccess();
    }

    /**
     * Get company-specific cache key.
     */
    protected function companyCacheKey(string $key): string
    {
        return $this->getCompanyContext()->cacheKey($key);
    }

    /**
     * Generate error response for missing Samsara configuration.
     */
    protected function noSamsaraAccessResponse(): string
    {
        return json_encode([
            'error' => true,
            'message' => 'Esta empresa no tiene configurada la integración con Samsara. ' .
                         'Contacta al administrador para configurar la API key.',
        ], JSON_UNESCAPED_UNICODE);
    }
}

