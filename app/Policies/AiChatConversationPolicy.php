<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AiChatConversation;
use App\Models\User;

class AiChatConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    public function view(User $user, AiChatConversation $conversation): bool
    {
        return $user->role === UserRole::Student
            && $conversation->user_id === $user->id;
    }

    public function update(User $user, AiChatConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function delete(User $user, AiChatConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
