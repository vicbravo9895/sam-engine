<?php

declare(strict_types=1);

namespace App\Neuron;

use App\Models\Company;
use App\Models\User;
use App\Samsara\Client\SamsaraClient;

/**
 * Company context holder for multi-tenant operations.
 * 
 * This class encapsulates all company-specific data needed for
 * the AI agent and tools to operate in a multi-tenant environment.
 * It ensures data isolation between companies.
 */
class CompanyContext
{
    private static ?self $instance = null;

    private function __construct(
        private readonly int $companyId,
        private readonly string $companyName,
        private readonly ?string $samsaraApiKey,
        private readonly int $userId,
        private readonly string $userRole,
    ) {}

    /**
     * Create context from a user.
     */
    public static function fromUser(User $user): self
    {
        // Force a fresh load of the company to ensure we have all attributes
        // (the Inertia middleware may have loaded a partial company with only id,name)
        $company = Company::find($user->company_id);

        if (!$company) {
            throw new \RuntimeException(
                'El usuario no está asociado a ninguna empresa. Contacta al administrador.'
            );
        }

        self::$instance = new self(
            companyId: $company->id,
            companyName: $company->name,
            samsaraApiKey: $company->getSamsaraApiKey(),
            userId: $user->id,
            userRole: $user->role,
        );

        return self::$instance;
    }

    /**
     * Get the current context instance.
     */
    public static function current(): ?self
    {
        return self::$instance;
    }

    /**
     * Set the current context instance (for testing or manual setup).
     */
    public static function setCurrent(?self $context): void
    {
        self::$instance = $context;
    }

    /**
     * Clear the current context.
     */
    public static function clear(): void
    {
        self::$instance = null;
    }

    /**
     * Get the company ID.
     */
    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    /**
     * Get the company name.
     */
    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    /**
     * Get the Samsara API key.
     */
    public function getSamsaraApiKey(): ?string
    {
        return $this->samsaraApiKey;
    }

    /**
     * Check if company has Samsara configured.
     */
    public function hasSamsaraAccess(): bool
    {
        return !empty($this->samsaraApiKey);
    }

    /**
     * Get the user ID.
     */
    public function getUserId(): int
    {
        return $this->userId;
    }

    /**
     * Get the user role.
     */
    public function getUserRole(): string
    {
        return $this->userRole;
    }

    /**
     * Create a SamsaraClient configured for this company.
     */
    public function createSamsaraClient(): SamsaraClient
    {
        if (!$this->hasSamsaraAccess()) {
            throw new \RuntimeException(
                'Esta empresa no tiene configurada la integración con Samsara. ' .
                'Contacta al administrador para configurar la API key.'
            );
        }

        return new SamsaraClient($this->samsaraApiKey);
    }

    /**
     * Get cache key prefix for this company.
     */
    public function getCacheKeyPrefix(): string
    {
        return "company_{$this->companyId}_";
    }

    /**
     * Generate a company-specific cache key.
     */
    public function cacheKey(string $key): string
    {
        return $this->getCacheKeyPrefix() . $key;
    }
}

