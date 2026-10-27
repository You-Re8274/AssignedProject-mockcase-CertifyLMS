<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\ActivityNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ActivityNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_is_sent_only_to_active_students_and_coaches_other_than_actor(): void
    {
        Notification::fake();

        $actor = User::factory()->student()->inProgress()->create();
        $activeStudent = User::factory()->student()->inProgress()->create();
        $activeCoach = User::factory()->coach()->inProgress()->create();
        $invitedStudent = User::factory()->student()->invited()->create();
        $graduatedCoach = User::factory()->coach()->graduated()->create();
        $admin = User::factory()->admin()->inProgress()->create();

        $service = app(ActivityNotificationService::class);
        foreach ([$actor, $activeStudent, $activeCoach, $invitedStudent, $graduatedCoach, $admin] as $recipient) {
            $service->send(
                recipient: $recipient,
                actor: $actor,
                type: 'qa_reply_received',
                title: '新しい回答があります',
                message: '質問に回答が投稿されました。',
                actionUrl: route('qa-board.index'),
            );
        }

        Notification::assertNotSentTo($actor, ActivityNotification::class);
        Notification::assertSentTo($activeStudent, ActivityNotification::class);
        Notification::assertSentTo($activeCoach, ActivityNotification::class);
        Notification::assertNotSentTo($invitedStudent, ActivityNotification::class);
        Notification::assertNotSentTo($graduatedCoach, ActivityNotification::class);
        Notification::assertNotSentTo($admin, ActivityNotification::class);
    }

    public function test_notification_provides_database_and_mail_channels(): void
    {
        $recipient = User::factory()->student()->inProgress()->create();
        $notification = new ActivityNotification([
            'notification_type' => 'chat_message_received',
            'title' => '新しいメッセージがあります',
            'message' => 'チャットにメッセージが届きました。',
            'body' => 'チャットにメッセージが届きました。',
            'action_url' => route('chat.index'),
        ]);

        $this->assertSame(['database', 'mail'], $notification->via($recipient));
        $this->assertSame('chat_message_received', $notification->toDatabase($recipient)['notification_type']);
        $this->assertInstanceOf(MailMessage::class, $notification->toMail($recipient));
        $this->assertSame('新しいメッセージがあります', $notification->toMail($recipient)->subject);
    }

    public function test_notification_delivery_persists_the_in_app_record(): void
    {
        $actor = User::factory()->student()->inProgress()->create();
        $recipient = User::factory()->coach()->inProgress()->create();

        app(ActivityNotificationService::class)->send(
            recipient: $recipient,
            actor: $actor,
            type: 'chat_message_received',
            title: '新しいメッセージがあります',
            message: 'チャットにメッセージが届きました。',
            actionUrl: route('chat.index'),
        );

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $recipient->id,
            'notifiable_type' => $recipient->getMorphClass(),
            'type' => ActivityNotification::class,
        ]);
    }
}
