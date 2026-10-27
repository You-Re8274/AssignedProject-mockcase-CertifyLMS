<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Student, UserRole::Coach], true);
    }

    public function view(User $user, DatabaseNotification $notification): bool
    {
        return $this->owns($user, $notification);
    }

    public function markAsRead(User $user, DatabaseNotification $notification): bool
    {
        return $this->owns($user, $notification);
    }

    private function owns(User $user, DatabaseNotification $notification): bool
    {
        return $this->viewAny($user)
            && $notification->notifiable_type === $user->getMorphClass()
            && (string) $notification->notifiable_id === (string) $user->getKey();
    }
}
