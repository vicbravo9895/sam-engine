<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->id === $conversation->user_id;
    }

    public function send(User $user, Conversation $conversation): bool
    {
        return $user->id === $conversation->user_id;
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $user->id === $conversation->user_id || $user->isAdmin();
    }
}
