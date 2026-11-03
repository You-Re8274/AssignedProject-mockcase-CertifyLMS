<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnnouncementControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_announcement_index_create_form_and_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $student = $this->activeStudent();
        $announcement = Announcement::factory()
            ->forUser($student->id)
            ->create(['created_by_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertViewIs('announcement.management.index')
            ->assertSee($announcement->title);

        $this->get(route('admin.announcements.create'))
            ->assertOk()
            ->assertViewIs('announcement.management.create')
            ->assertViewHas('students', fn ($students) => $students->contains('id', $student->id));

        $this->get(route('admin.announcements.show', $announcement))
            ->assertOk()
            ->assertViewIs('announcement.management.show')
            ->assertSee($announcement->body);
    }

    public function test_all_students_target_notifies_only_active_students(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $activeStudent = $this->activeStudent();
        $graduatedStudent = User::factory()->student()->graduated()->create();
        $withdrawnStudent = User::factory()->student()->withdrawn()->create();
        $invitedStudent = User::factory()->student()->invited()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $deletedStudent = User::factory()->student()->inProgress()->create();
        $deletedStudent->delete();

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => '全受講生向け',
            'body' => 'すべての受講中ユーザーに届く本文です。',
            'target_type' => AnnouncementTargetType::AllStudents->value,
        ]);

        $announcement = Announcement::query()->where('title', '全受講生向け')->firstOrFail();
        $response->assertRedirect(route('admin.announcements.show', $announcement));
        $this->assertSame(1, $announcement->dispatched_count);
        $this->assertNotNull($announcement->dispatched_at);
        Notification::assertSentTo($activeStudent, ActivityNotification::class);
        foreach ([$graduatedStudent, $withdrawnStudent, $invitedStudent, $coach, $deletedStudent] as $excludedUser) {
            Notification::assertNotSentTo($excludedUser, ActivityNotification::class);
        }
    }

    public function test_certification_target_notifies_only_enrolled_active_students(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $enrolledStudent = $this->activeStudent();
        Enrollment::factory()->for($enrolledStudent)->for($certification)->create();
        $otherCertificationStudent = $this->activeStudent();
        Enrollment::factory()->for($otherCertificationStudent)->create();
        $graduatedStudent = User::factory()->student()->graduated()->create();
        Enrollment::factory()->for($graduatedStudent)->for($certification)->create();

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => '資格別お知らせ',
            'body' => '資格の受講生向け本文です。',
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $certification->id,
        ]);

        $announcement = Announcement::query()->where('title', '資格別お知らせ')->firstOrFail();
        $response->assertRedirect(route('admin.announcements.show', $announcement));
        $this->assertSame(1, $announcement->dispatched_count);
        Notification::assertSentTo($enrolledStudent, ActivityNotification::class);
        Notification::assertNotSentTo($otherCertificationStudent, ActivityNotification::class);
        Notification::assertNotSentTo($graduatedStudent, ActivityNotification::class);
    }

    public function test_user_target_notifies_only_the_selected_active_student(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $selectedStudent = $this->activeStudent();
        $otherStudent = $this->activeStudent();

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => '個別お知らせ',
            'body' => '指定した受講生だけに届く本文です。',
            'target_type' => AnnouncementTargetType::User->value,
            'target_user_id' => $selectedStudent->id,
        ]);

        $announcement = Announcement::query()->where('title', '個別お知らせ')->firstOrFail();
        $response->assertRedirect(route('admin.announcements.show', $announcement));
        $this->assertSame(1, $announcement->dispatched_count);
        Notification::assertSentTo($selectedStudent, ActivityNotification::class);
        Notification::assertNotSentTo($otherStudent, ActivityNotification::class);
    }

    public function test_sent_announcement_uses_database_and_mail_channels_with_full_body_and_detail_url(): void
    {
        $admin = User::factory()->admin()->create();
        $student = $this->activeStudent();
        $body = str_repeat('詳細本文。', 35);

        $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => 'メール配信内容',
            'body' => $body,
            'target_type' => AnnouncementTargetType::User->value,
            'target_user_id' => $student->id,
        ])->assertRedirect();

        $announcement = Announcement::query()->where('title', 'メール配信内容')->firstOrFail();
        $notification = $student->notifications()->firstOrFail();
        $this->assertSame($body, $notification->data['body']);
        $this->assertSame('admin_announcement', $notification->data['notification_type']);
        $this->assertSame($announcement->id, $notification->data['announcement_id']);
        $this->assertSame(route('notifications.show', $notification), $notification->data['action_url']);

        $mail = (new ActivityNotification($notification->data))->toMail($student);
        $this->assertContains('database', (new ActivityNotification($notification->data))->via($student));
        $this->assertContains('mail', (new ActivityNotification($notification->data))->via($student));
        $this->assertSame('メール配信内容', $mail->subject);
        $this->assertSame(route('notifications.show', $notification), $mail->actionUrl);
        $sentMessages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $sentMessages);
        $this->assertSame(
            [$student->email],
            array_map(
                static fn ($recipient): string => $recipient->getAddress(),
                $sentMessages->first()->getEnvelope()->getRecipients(),
            ),
        );
    }

    public function test_invalid_announcement_content_or_target_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $activeStudent = $this->activeStudent();
        $graduatedStudent = User::factory()->student()->graduated()->create();

        $this->actingAs($admin)
            ->from(route('admin.announcements.create'))
            ->post(route('admin.announcements.store'), [
                'title' => '',
                'body' => '',
                'target_type' => 'unknown',
            ])
            ->assertSessionHasErrors(['title', 'body', 'target_type']);

        $this->post(route('admin.announcements.store'), [
            'title' => '資格対象なし',
            'body' => '本文',
            'target_type' => AnnouncementTargetType::Certification->value,
        ])->assertSessionHasErrors('target_certification_id');

        $this->post(route('admin.announcements.store'), [
            'title' => '卒業生対象',
            'body' => '本文',
            'target_type' => AnnouncementTargetType::User->value,
            'target_user_id' => $graduatedStudent->id,
        ])->assertSessionHasErrors('target_user_id');

        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseMissing('announcements', ['target_user_id' => $activeStudent->id]);
    }

    public function test_non_admin_cannot_access_announcement_management_routes(): void
    {
        $student = $this->activeStudent();
        $coach = User::factory()->coach()->inProgress()->create();

        foreach ([$student, $coach] as $user) {
            $this->actingAs($user)
                ->get(route('admin.announcements.index'))
                ->assertForbidden();
            $this->get(route('admin.announcements.create'))->assertForbidden();
            $this->post(route('admin.announcements.store'), [
                'title' => '拒否される',
                'body' => '拒否される本文',
                'target_type' => AnnouncementTargetType::AllStudents->value,
            ])->assertForbidden();
        }
    }

    public function test_announcement_has_no_edit_delete_or_redispatch_routes(): void
    {
        $admin = User::factory()->admin()->create();
        $announcement = Announcement::factory()->create(['created_by_user_id' => $admin->id]);

        $this->assertFalse(Route::has('admin.announcements.edit'));
        $this->assertFalse(Route::has('admin.announcements.update'));
        $this->assertFalse(Route::has('admin.announcements.destroy'));
        $this->assertFalse(Route::has('admin.announcements.redispatch'));
        $this->actingAs($admin)
            ->get('/admin/announcements/'.$announcement->id.'/edit')
            ->assertNotFound();
        $this->delete('/admin/announcements/'.$announcement->id)->assertStatus(405);
    }

    public function test_user_can_open_own_announcement_notification_and_it_becomes_read(): void
    {
        $student = $this->activeStudent();
        $notification = $this->createAnnouncementNotification($student);

        $this->actingAs($student)
            ->get(route('notifications.show', $notification))
            ->assertOk()
            ->assertViewIs('notifications.show')
            ->assertSee('お知らせの全文です。');

        $this->assertNotNull($notification->fresh()->read_at);

        $this->post(route('notifications.markAsRead', $notification))
            ->assertRedirect(route('notifications.show', $notification));
    }

    public function test_user_cannot_open_another_users_notification_detail(): void
    {
        $owner = $this->activeStudent();
        $otherStudent = $this->activeStudent();
        $notification = $this->createAnnouncementNotification($owner);

        $this->actingAs($otherStudent)
            ->get(route('notifications.show', $notification))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_coach_and_admin_cannot_access_student_notification_detail(): void
    {
        $student = $this->activeStudent();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $notification = $this->createAnnouncementNotification($student);

        $this->actingAs($coach)
            ->get(route('notifications.show', $notification))
            ->assertNotFound();
        $this->actingAs($admin)
            ->get(route('notifications.show', $notification))
            ->assertNotFound();
    }

    private function activeStudent(): User
    {
        return User::factory()->student()->inProgress()->create();
    }

    private function createAnnouncementNotification(User $user): DatabaseNotification
    {
        $notificationId = (string) Str::uuid();

        return $user->notifications()->create([
            'id' => $notificationId,
            'type' => ActivityNotification::class,
            'data' => [
                'notification_type' => 'admin_announcement',
                'announcement_id' => (string) Str::ulid(),
                'title' => '受信済みお知らせ',
                'message' => 'お知らせの全文です。',
                'body' => 'お知らせの全文です。',
                'action_url' => route('notifications.show', $notificationId),
            ],
        ]);
    }
}
