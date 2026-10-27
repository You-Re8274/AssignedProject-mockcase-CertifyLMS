<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\NotificationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

class NotificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_students_and_coaches_can_view_their_own_notifications(): void
    {
        $policy = new NotificationPolicy;
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $studentNotification = $this->notificationFor($student);
        $coachNotification = $this->notificationFor($coach);
        $otherUserNotification = $this->notificationFor($coach);

        $this->assertTrue($policy->viewAny($student));
        $this->assertTrue($policy->view($student, $studentNotification));
        $this->assertTrue($policy->markAsRead($coach, $coachNotification));
        $this->assertFalse($policy->view($student, $otherUserNotification));
        $this->assertFalse($policy->markAsRead($student, $otherUserNotification));
        $this->assertFalse($policy->viewAny($admin));
        $this->assertFalse($policy->view($admin, $studentNotification));
    }

    private function notificationFor(User $user): DatabaseNotification
    {
        $notification = new DatabaseNotification;
        $notification->forceFill([
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
        ]);

        return $notification;
    }
}
