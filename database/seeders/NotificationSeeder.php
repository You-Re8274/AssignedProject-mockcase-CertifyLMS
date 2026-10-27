<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\ChatRoom;
use App\Models\Meeting;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Seeder;

final class NotificationSeeder extends Seeder
{
    private const NOTIFICATION_COUNT = 18;

    public function run(): void
    {
        $recipients = [
            ['email' => 'student@certify-lms.test', 'id_offset' => 0],
            ['email' => 'coach@certify-lms.test', 'id_offset' => 100],
        ];

        foreach ($recipients as $recipient) {
            $user = User::query()->where('email', $recipient['email'])->first();
            if ($user === null) {
                $this->command?->warn("NotificationSeeder: {$recipient['email']} が存在しないため通知をスキップします。");

                continue;
            }

            $this->seedNotifications($user, $recipient['id_offset']);
        }
    }

    private function seedNotifications(User $user, int $idOffset): void
    {
        $actionUrls = $this->actionUrlsFor($user);
        $types = [
            'chat_message_received' => [
                'title' => 'チャットに新しいメッセージがあります',
                'message' => '担当ルームに新しいメッセージが届きました。',
            ],
            'qa_reply_received' => [
                'title' => '質問掲示板に新しい回答があります',
                'message' => 'あなたが閲覧できる質問に回答が投稿されました。',
            ],
            'meeting_reserved' => [
                'title' => '面談が予約されました',
                'message' => '面談の予約内容を確認してください。',
            ],
        ];
        $typeNames = array_keys($types);

        for ($index = 0; $index < self::NOTIFICATION_COUNT; $index++) {
            $type = $typeNames[$index % count($typeNames)];
            $createdAt = now()->subDays(intdiv($index, 3))->subHours($index % 3);
            $isRead = $index % 3 === 1;
            $id = sprintf('00000000-0000-4000-8000-%012d', $idOffset + $index + 1);

            $user->notifications()->updateOrCreate(
                ['id' => $id],
                [
                    'type' => $type,
                    'data' => [
                        'notification_type' => $type,
                        'title' => $types[$type]['title'],
                        'message' => $types[$type]['message'],
                        'body' => $types[$type]['message'],
                        'action_url' => $actionUrls[$type],
                    ],
                    'read_at' => $isRead ? $createdAt->copy()->addMinutes(10) : null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ],
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function actionUrlsFor(User $user): array
    {
        $chatRoom = ChatRoom::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
            ->first();

        $qaThreadQuery = QaThread::query();
        if ($user->role === UserRole::Student) {
            $qaThreadQuery->where('user_id', $user->id);
        } else {
            $qaThreadQuery->whereHas(
                'certification.coaches',
                fn ($query) => $query->where('users.id', $user->id),
            );
        }
        $qaThread = $qaThreadQuery->first();

        $meeting = Meeting::query()
            ->where($user->role === UserRole::Coach ? 'coach_id' : 'student_id', $user->id)
            ->first();

        return [
            'chat_message_received' => $chatRoom !== null
                ? route('chat.show', $chatRoom)
                : route('dashboard.index'),
            'qa_reply_received' => $qaThread !== null
                ? route('qa-board.show', $qaThread)
                : route('dashboard.index'),
            'meeting_reserved' => $meeting !== null
                ? route('meetings.show', $meeting)
                : route('dashboard.index'),
        ];
    }
}
