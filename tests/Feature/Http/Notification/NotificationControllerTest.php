<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_and_coach_can_view_only_their_paginated_notifications(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $this->createNotification($student, unread: true);
        $this->createNotification($student, unread: false);
        $this->createNotification($coach, unread: true);
        $this->createNotification($coach, unread: false);

        foreach ([$student, $coach] as $user) {
            $this->actingAs($user)
                ->get(route('notifications.index'))
                ->assertOk()
                ->assertViewIs('notifications.index')
                ->assertViewHas('unreadCount', 1)
                ->assertViewHas('notifications', fn ($notifications) => $notifications->total() === 2);

            $this->get(route('notifications.index', ['tab' => 'unread']))
                ->assertOk()
                ->assertViewHas('notifications', fn ($notifications) => $notifications->total() === 1);
        }
    }

    public function test_notification_list_is_paginated(): void
    {
        $student = User::factory()->student()->create();
        foreach (range(1, 21) as $index) {
            $this->createNotification($student, unread: $index % 2 === 0);
        }

        $this->actingAs($student)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertViewHas('notifications', fn ($notifications) => $notifications->total() === 21
                && $notifications->perPage() === 20
                && $notifications->count() === 20);

        $this->get(route('notifications.index', ['page' => 2]))
            ->assertOk()
            ->assertViewHas('notifications', fn ($notifications) => $notifications->count() === 1);
    }

    public function test_notification_click_marks_only_own_notification_read_and_redirects_to_action(): void
    {
        $student = User::factory()->student()->create();
        $notification = $this->createNotification(
            $student,
            unread: true,
            actionUrl: route('qa-board.index'),
        );

        $this->actingAs($student)
            ->post(route('notifications.markAsRead', $notification->id))
            ->assertRedirect(route('qa-board.index'));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_other_users_notifications_cannot_be_read(): void
    {
        $owner = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $notification = $this->createNotification($owner, unread: true);

        $this->actingAs($otherStudent)
            ->post(route('notifications.markAsRead', $notification->id))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_external_action_url_falls_back_to_notification_list(): void
    {
        $student = User::factory()->student()->create();
        $notification = $this->createNotification(
            $student,
            unread: true,
            actionUrl: 'https://attacker.example/phishing',
        );

        $this->actingAs($student)
            ->post(route('notifications.markAsRead', $notification->id))
            ->assertRedirect(route('notifications.index'));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_all_as_read_updates_only_the_authenticated_users_unread_notifications(): void
    {
        $student = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $unread = $this->createNotification($student, unread: true);
        $alreadyRead = $this->createNotification($student, unread: false);
        $otherUnread = $this->createNotification($otherStudent, unread: true);

        $this->actingAs($student)
            ->post(route('notifications.markAllAsRead'))
            ->assertRedirect(route('notifications.index'));

        $this->assertNotNull($unread->fresh()->read_at);
        $this->assertNotNull($alreadyRead->fresh()->read_at);
        $this->assertNull($otherUnread->fresh()->read_at);
    }

    public function test_admin_is_not_allowed_to_view_student_and_coach_notifications(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('notifications.index'))
            ->assertForbidden();

        $this->post(route('notifications.markAllAsRead'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->post(route('notifications.markAllAsRead'))->assertRedirect(route('login'));
    }

    private function createNotification(
        User $user,
        bool $unread,
        ?string $actionUrl = null,
    ): DatabaseNotification {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'qa_reply_received',
            'data' => [
                'notification_type' => 'qa_reply_received',
                'title' => '質問に回答がありました',
                'message' => '新しい回答を確認してください。',
                'body' => '新しい回答を確認してください。',
                'action_url' => $actionUrl ?? route('dashboard.index'),
            ],
            'read_at' => $unread ? null : now(),
        ]);
    }
}
