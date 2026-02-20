<?php

namespace App\Observers;

use App\Models\Company;

/**
 * Observer for Company model. Reserved for future use (e.g. usage/billing hooks).
 */
class CompanyObserver
{
    public function created(Company $company): void
    {
        // No-op: Stripe/Cashier removed; usage is recorded via RecordUsageEventJob.
    }
}
