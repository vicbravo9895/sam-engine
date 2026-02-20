<?php

namespace App\Providers;

use App\Listeners\AuthEventListener;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\SafetySignal;
use App\Models\Alert;
use App\Models\User;
use App\Observers\CompanyObserver;
use App\Observers\SafetySignalObserver;
use App\Policies\CompanyPolicy;
use App\Policies\ContactPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\AlertPolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use Laravel\Pulse\Facades\Pulse;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePulse();
        $this->configureFeatureFlags();
        $this->registerPolicies();
        $this->registerAuthListeners();
        Company::observe(CompanyObserver::class);
        SafetySignal::observe(SafetySignalObserver::class);
    }

    protected function registerPolicies(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(Alert::class, AlertPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);
    }

    protected function registerAuthListeners(): void
    {
        $listener = new AuthEventListener();
        Event::listen(Login::class, [$listener, 'handleLogin']);
        Event::listen(Logout::class, [$listener, 'handleLogout']);
    }

    /**
     * Register feature flags for phased rollout per tenant.
     */
    protected function configureFeatureFlags(): void
    {
        Feature::define('ledger-v1', fn (Company $company) => true);
        Feature::define('notifications-v2', fn (Company $company) => true);
        Feature::define('metering-v1', fn (Company $company) => false);
        Feature::define('attention-engine-v1', fn (Company $company) => true);
    }

    /**
     * Configure Laravel Pulse for SAM monitoring.
     */
    protected function configurePulse(): void
    {
        // Autorización para el dashboard de Pulse
        // Solo super admins pueden ver el dashboard
        Gate::define('viewPulse', function (User $user) {
            return $user->isSuperAdmin();
        });

        // Configurar resolución de usuarios para Pulse
        Pulse::user(fn (User $user) => [
            'name' => $user->name,
            'extra' => $user->email,
            'avatar' => $user->avatar_url ?? $this->getGravatarUrl($user->email),
        ]);

        // Registrar componentes Livewire para las cards personalizadas
        Livewire::component('pulse.alerts-processed', \App\Pulse\Cards\AlertsProcessed::class);
        Livewire::component('pulse.token-consumption', \App\Pulse\Cards\TokenConsumption::class);
        Livewire::component('pulse.ai-performance', \App\Pulse\Cards\AiPerformance::class);
        Livewire::component('pulse.notification-status', \App\Pulse\Cards\NotificationStatus::class);
        Livewire::component('pulse.copilot-usage', \App\Pulse\Cards\CopilotUsage::class);

        // Filtrar entradas de Pulse para super admins (no registrar sus acciones)
        Pulse::filter(function ($entry) {
            // No filtrar nada por ahora, registrar todo
            return true;
        });
    }

    /**
     * Generate Gravatar URL for user avatar.
     */
    protected function getGravatarUrl(string $email): string
    {
        $hash = md5(strtolower(trim($email)));
        return "https://www.gravatar.com/avatar/{$hash}?d=mp&s=64";
    }
}
