<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            // In local environment, record everything
            if ($isLocal) {
                return true;
            }

            // In production, record ALL entries for super admin observability
            // The gate() method already controls who can access Telescope,
            // so we can safely record everything here
            // This ensures all requests, queries, HTTP client calls, jobs, etc. are logged
            
            // Always record everything - let the gate handle access control
            return true;
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     * Super admins can always access Telescope, even in production.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function ($user) {
            // Super admins can always access Telescope
            if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return true;
            }

            // Allow specific emails if needed (for non-super-admin access)
            return in_array($user->email ?? [], [
                //
            ]);
        });
    }
}
