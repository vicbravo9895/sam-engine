<?php

namespace App\Providers;

use App\Models\SafetySignal;
use App\Models\User;
use App\Observers\SafetySignalObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
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
        SafetySignal::observe(SafetySignalObserver::class);
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
