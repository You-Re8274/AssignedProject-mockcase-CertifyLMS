<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationApiControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_the_latest_ten_owned_notifications_and_unread_count(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $otherUser = User::factory()->student()->inProgress()->create();
        $oldestNotification = null;
        $newestNotification = null;

        foreach (range(1, 12) as $index) {
            $notification = $this->createNotification(
                $user,
                unread: $index % 2 === 0,
                createdAt: now()->addSeconds($index),
            );
            $oldestNotification ??= $notification;
            $newestNotification = $notification;
        }

        $this->createNotification($otherUser, unread: true);
        $this->actingAs($user);

        $response = $this->getJson(route('api.v1.notifications.index'));

        $response->assertOk()
            ->assertJsonCount(10, 'notifications')
            ->assertJsonPath('notifications.0.id', $newestNotification->id)
            ->assertJsonPath('unread_count', 6)
            ->assertJsonPath('notifications.0.title', 'テスト通知')
            ->assertJsonPath('notifications.0.message', '通知内容');

        $this->assertFalse(
            collect($response->json('notifications'))->contains('id', $oldestNotification->id),
        );
    }

    public function test_guest_cannot_access_notification_api(): void
    {
        $this->getJson(route('api.v1.notifications.index'))->assertUnauthorized();
        $this->postJson(route('api.v1.notifications.read-all'))->assertUnauthorized();
    }

    public function test_user_can_read_only_their_own_notification(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $otherUser = User::factory()->student()->inProgress()->create();
        $ownNotification = $this->createNotification($user, unread: true);
        $otherNotification = $this->createNotification($otherUser, unread: true);
        $this->actingAs($user);

        $this->postJson(route('api.v1.notifications.read', $ownNotification->id))
            ->assertOk()
            ->assertJsonPath('notification.id', $ownNotification->id)
            ->assertJsonPath('notification.read_at', fn ($readAt) => is_string($readAt))
            ->assertJsonPath('unread_count', 0);

        $this->postJson(route('api.v1.notifications.read', $otherNotification->id))
            ->assertNotFound();

        $this->assertNotNull($ownNotification->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);
    }

    public function test_mark_all_as_read_changes_only_authenticated_users_notifications(): void
    {
        $user = User::factory()->coach()->inProgress()->create();
        $otherUser = User::factory()->student()->inProgress()->create();
        $ownUnread = $this->createNotification($user, unread: true);
        $ownRead = $this->createNotification($user, unread: false);
        $otherUnread = $this->createNotification($otherUser, unread: true);
        $this->actingAs($user);

        $this->postJson(route('api.v1.notifications.read-all'))
            ->assertOk()
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('unread_count', 0);

        $this->assertNotNull($ownUnread->fresh()->read_at);
        $this->assertNotNull($ownRead->fresh()->read_at);
        $this->assertNull($otherUnread->fresh()->read_at);
    }

    public function test_admin_is_not_allowed_to_use_notification_api(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->getJson(route('api.v1.notifications.index'))->assertForbidden();
        $this->postJson(route('api.v1.notifications.read-all'))->assertForbidden();
    }

    public function test_external_action_url_is_replaced_with_notification_page_url(): void
    {
        $user = User::factory()->student()->inProgress()->create();
        $this->createNotification(
            $user,
            unread: true,
            actionUrl: 'https://attacker.example/phishing',
        );
        $this->actingAs($user);

        $this->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonPath(
                'notifications.0.action_url',
                route('notifications.index'),
            );

    }

    private function createNotification(
        User $user,
        bool $unread,
        ?string $actionUrl = null,
        ?Carbon $createdAt = null,
    ): DatabaseNotification {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'qa_reply_received',
            'data' => [
                'notification_type' => 'qa_reply_received',
                'title' => 'テスト通知',
                'message' => '通知内容',
                'action_url' => $actionUrl ?? route('dashboard.index'),
            ],
            'read_at' => $unread ? null : now(),
            'created_at' => $createdAt ?? now(),
        ]);
    }
}
