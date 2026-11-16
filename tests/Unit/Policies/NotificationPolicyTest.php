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

    public function test_students_and_coaches_can_access_notifications_but_admins_cannot(): void
    {
        $policy = new NotificationPolicy;
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $studentNotification = $this->notificationFor($student);
        $coachNotification = $this->notificationFor($coach);
        $adminNotification = $this->notificationFor($admin);

        $this->assertTrue($policy->viewAny($student));
        $this->assertTrue($policy->viewAny($coach));
        $this->assertFalse($policy->viewAny($admin));

        $this->assertTrue($policy->view($student, $studentNotification));
        $this->assertFalse($policy->view($admin, $studentNotification));
        $this->assertTrue($policy->markAsRead($student, $studentNotification));
        $this->assertTrue($policy->view($coach, $coachNotification));
        $this->assertTrue($policy->markAsRead($coach, $coachNotification));
        $this->assertFalse($policy->view($admin, $adminNotification));
        $this->assertFalse($policy->markAsRead($admin, $adminNotification));
    }

    public function test_notification_must_match_both_owner_id_and_morph_type(): void
    {
        $policy = new NotificationPolicy;
        $student = User::factory()->student()->create();

        $wrongOwner = $this->notificationFor($student);
        $wrongOwner->notifiable_id = 'another-user-id';
        $wrongMorph = $this->notificationFor($student);
        $wrongMorph->notifiable_type = 'App\\Models\\OtherNotifiable';

        $this->assertFalse($policy->view($student, $wrongOwner));
        $this->assertFalse($policy->markAsRead($student, $wrongOwner));
        $this->assertFalse($policy->view($student, $wrongMorph));
        $this->assertFalse($policy->markAsRead($student, $wrongMorph));
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
