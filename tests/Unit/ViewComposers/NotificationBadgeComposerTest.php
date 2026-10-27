<?php

declare(strict_types=1);

namespace Tests\Unit\ViewComposers;

use App\Models\User;
use App\View\Composers\NotificationBadgeComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Tests\TestCase;

class NotificationBadgeComposerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_zero_when_unauthenticated(): void
    {
        $captured = null;
        $view = $this->mockView(function ($name, $value) use (&$captured): void {
            if ($name === 'notificationBadge') {
                $captured = $value;
            }
        });

        (new NotificationBadgeComposer)->compose($view);

        $this->assertSame(0, $captured);
    }

    public function test_returns_only_authenticated_users_unread_notification_count(): void
    {
        $student = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $this->createNotification($student, unread: true);
        $this->createNotification($student, unread: true);
        $this->createNotification($student, unread: false);
        $this->createNotification($otherStudent, unread: true);
        $this->actingAs($student);

        $captured = null;
        $view = $this->mockView(function ($name, $value) use (&$captured): void {
            if ($name === 'notificationBadge') {
                $captured = $value;
            }
        });

        (new NotificationBadgeComposer)->compose($view);

        $this->assertSame(2, $captured);
    }

    public function test_returns_zero_for_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createNotification($admin, unread: true);
        $this->actingAs($admin);

        $captured = null;
        $view = $this->mockView(function ($name, $value) use (&$captured): void {
            if ($name === 'notificationBadge') {
                $captured = $value;
            }
        });

        (new NotificationBadgeComposer)->compose($view);

        $this->assertSame(0, $captured);
    }

    private function mockView(callable $onWith): View
    {
        $view = $this->createMock(View::class);
        $view->method('with')->willReturnCallback(function ($name, $value = null) use ($onWith) {
            $onWith($name, $value);

            return $name;
        });

        return $view;
    }

    private function createNotification(User $user, bool $unread): void
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'qa_reply_received',
            'data' => ['notification_type' => 'qa_reply_received'],
            'read_at' => $unread ? null : now(),
        ]);
    }
}
