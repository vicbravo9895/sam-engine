<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Copilot private channel - multi-tenant authorization.
 * 
 * Only the owner of the conversation (same user_id AND company_id) can
 * subscribe to receive streaming events.
 */
Broadcast::channel('copilot.{threadId}', function (User $user, string $threadId) {
    return Conversation::where('thread_id', $threadId)
        ->where('user_id', $user->id)
        ->where('company_id', $user->company_id)
        ->exists();
});
