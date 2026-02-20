<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\DomainEventEmitter;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class AuthEventListener
{
    public function handleLogin(Login $event): void
    {
        /** @var User $user */
        $user = $event->user;

        if (!$user->company_id) {
            return;
        }

        DomainEventEmitter::emit(
            companyId: $user->company_id,
            entityType: 'user',
            entityId: (string) $user->id,
            eventType: 'auth.login',
            payload: [
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'guard' => $event->guard,
            ],
            actorType: 'user',
            actorId: (string) $user->id,
        );
    }

    public function handleLogout(Logout $event): void
    {
        /** @var User|null $user */
        $user = $event->user;

        if (!$user || !$user->company_id) {
            return;
        }

        DomainEventEmitter::emit(
            companyId: $user->company_id,
            entityType: 'user',
            entityId: (string) $user->id,
            eventType: 'auth.logout',
            payload: [
                'ip' => request()->ip(),
            ],
            actorType: 'user',
            actorId: (string) $user->id,
        );
    }
}
