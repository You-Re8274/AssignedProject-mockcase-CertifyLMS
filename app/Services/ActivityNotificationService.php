<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\ActivityNotification;

final class ActivityNotificationService
{
    public function send(
        ?User $recipient,
        User $actor,
        string $type,
        string $title,
        string $message,
        string $actionUrl,
    ): void {
        if ($recipient === null
            || $recipient->is($actor)
            || $recipient->status !== UserStatus::InProgress
            || ! in_array($recipient->role, [UserRole::Student, UserRole::Coach], true)) {
            return;
        }

        $recipient->notify(new ActivityNotification([
            'notification_type' => $type,
            'title' => $title,
            'message' => $message,
            'body' => $message,
            'action_url' => $actionUrl,
        ]));
    }
}
