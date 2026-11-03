<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class AnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('role', UserRole::Admin->value)->first();
        $allStudents = $this->activeStudents()->orderBy('created_at')->get();

        if ($admin === null || $allStudents->isEmpty()) {
            $this->command?->warn('AnnouncementSeeder: 管理者または受講中の受講生がいないため、お知らせをスキップします。');

            return;
        }

        $targetStudent = $allStudents->first();
        $certification = Certification::query()
            ->whereHas('enrollments', fn (Builder $query) => $query
                ->whereHas('user', fn (Builder $userQuery) => $userQuery
                    ->where('role', UserRole::Student->value)
                    ->where('status', UserStatus::InProgress->value)))
            ->orderBy('name')
            ->first();

        $announcementSpecs = [
            [
                'title' => '【サンプル】メンテナンスのお知らせ',
                'body' => 'システムメンテナンスを予定しています。時間中は一部機能をご利用いただけない場合があります。',
                'target_type' => AnnouncementTargetType::AllStudents,
                'target_certification_id' => null,
                'target_user_id' => null,
                'recipients' => $allStudents,
            ],
            [
                'title' => '【サンプル】資格別のお知らせ',
                'body' => 'この資格に登録している受講生向けのお知らせです。',
                'target_type' => AnnouncementTargetType::Certification,
                'target_certification_id' => $certification?->id,
                'target_user_id' => null,
                'recipients' => $certification === null
                    ? collect()
                    : $this->activeStudents()
                        ->whereHas('enrollments', fn (Builder $query) => $query
                            ->where('certification_id', $certification->id))
                        ->orderBy('created_at')
                        ->get(),
            ],
            [
                'title' => '【サンプル】個別のお知らせ',
                'body' => '指定した受講生だけに届くお知らせのサンプルです。',
                'target_type' => AnnouncementTargetType::User,
                'target_certification_id' => null,
                'target_user_id' => $targetStudent->id,
                'recipients' => collect([$targetStudent]),
            ],
        ];

        foreach ($announcementSpecs as $spec) {
            if ($spec['target_type'] === AnnouncementTargetType::Certification && $certification === null) {
                $this->command?->warn('AnnouncementSeeder: 受講中の受講生が登録している資格がないため、資格指定のお知らせをスキップします。');

                continue;
            }

            /** @var Collection<int, User> $recipients */
            $recipients = $spec['recipients'];
            $dispatchedAt = now();
            $announcement = Announcement::query()->updateOrCreate(
                ['title' => $spec['title']],
                [
                    'body' => $spec['body'],
                    'target_type' => $spec['target_type']->value,
                    'target_certification_id' => $spec['target_certification_id'],
                    'target_user_id' => $spec['target_user_id'],
                    'created_by_user_id' => $admin->id,
                    'dispatched_count' => $recipients->count(),
                    'dispatched_at' => $dispatchedAt,
                ],
            );

            foreach ($recipients as $recipient) {
                $this->seedNotification($recipient, $announcement, $dispatchedAt);
            }
        }
    }

    /**
     * @return Builder<User>
     */
    private function activeStudents(): Builder
    {
        return User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value);
    }

    private function seedNotification(User $recipient, Announcement $announcement, Carbon $createdAt): void
    {
        $alreadySeeded = $recipient->notifications()
            ->where('type', ActivityNotification::class)
            ->get()
            ->contains(fn ($notification) => ($notification->data['announcement_id'] ?? null) === $announcement->id);

        if ($alreadySeeded) {
            return;
        }

        $notificationId = (string) Str::uuid();
        $recipient->notifications()->create([
            'id' => $notificationId,
            'type' => ActivityNotification::class,
            'data' => [
                'notification_type' => 'admin_announcement',
                'announcement_id' => $announcement->id,
                'title' => $announcement->title,
                'message' => Str::limit($announcement->body, 160),
                'body' => $announcement->body,
                'action_url' => url('/notifications/'.$notificationId),
            ],
            'read_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
