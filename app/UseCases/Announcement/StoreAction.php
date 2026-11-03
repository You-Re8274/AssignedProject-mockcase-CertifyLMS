<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StoreAction
{
    /**
     * @param array{title: string, body: string, target_type: string, target_certification_id?: string, target_user_id?: string} $data
     */
    public function __invoke(User $admin, array $data): Announcement
    {
        $targetType = AnnouncementTargetType::from($data['target_type']);
        $recipients = $this->recipients($targetType, $data);
        $announcement = Announcement::query()->create([
            'title' => $data['title'],
            'body' => $data['body'],
            'target_type' => $targetType,
            'target_certification_id' => $targetType === AnnouncementTargetType::Certification
                ? $data['target_certification_id']
                : null,
            'target_user_id' => $targetType === AnnouncementTargetType::User
                ? $data['target_user_id']
                : null,
            'created_by_user_id' => $admin->id,
            'dispatched_count' => 0,
            'dispatched_at' => null,
        ]);

        foreach ($recipients as $recipient) {
            $notificationId = (string) Str::uuid();
            $notification = new ActivityNotification([
                'notification_type' => 'admin_announcement',
                'announcement_id' => $announcement->id,
                'title' => $announcement->title,
                'message' => Str::limit($announcement->body, 160),
                'body' => $announcement->body,
                'action_url' => route('notifications.show', $notificationId),
            ]);
            $notification->id = $notificationId;

            DB::transaction(function () use ($recipient, $notification, $announcement): void {
                $recipient->notify($notification);
                $announcement->increment('dispatched_count');
            });
        }

        $announcement->forceFill(['dispatched_at' => now()])->save();

        return $announcement->refresh();
    }

    /**
     * @param array{target_certification_id?: string, target_user_id?: string} $data
     *
     * @return Collection<int, User>
     */
    private function recipients(AnnouncementTargetType $targetType, array $data): Collection
    {
        $query = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value);

        return match ($targetType) {
            AnnouncementTargetType::AllStudents => $query->orderBy('id')->get(),
            AnnouncementTargetType::Certification => $query
                ->whereHas('enrollments', fn (Builder $enrollments) => $enrollments
                    ->where('certification_id', $data['target_certification_id']))
                ->orderBy('id')
                ->get(),
            AnnouncementTargetType::User => $query
                ->whereKey($data['target_user_id'])
                ->get(),
        };
    }
}
