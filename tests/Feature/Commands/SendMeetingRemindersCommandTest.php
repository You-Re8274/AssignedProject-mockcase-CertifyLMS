<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\MeetingReminderWindow;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendMeetingRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_eve_window_notifies_both_parties_for_reserved_meetings_on_the_next_day_only(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00'));

        [$student, $coach, $meeting] = $this->createMeetingAt(now()->addDay()->setTime(10, 0));
        [, , $todayMeeting] = $this->createMeetingAt(now()->setTime(12, 0));
        [, , $laterMeeting] = $this->createMeetingAt(now()->addDays(2)->setTime(10, 0));
        [, , $canceledMeeting] = $this->createMeetingAt(
            now()->addDay()->setTime(11, 0),
            MeetingStatus::Canceled,
        );
        [, , $completedMeeting] = $this->createMeetingAt(
            now()->addDay()->setTime(12, 0),
            MeetingStatus::Completed,
        );
        [, , $alreadyRemindedMeeting] = $this->createMeetingAt(
            now()->addDay()->setTime(13, 0),
            MeetingStatus::Reserved,
            ['day_before_reminder_sent_at' => now()],
        );

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->assertExitCode(0)
            ->expectsOutput('eve のリマインダーを 1 件配信しました。');

        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(1, $coach->notifications()->count());
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $student->id,
            'type' => ActivityNotification::class,
        ]);
        $notification = $student->notifications()->firstOrFail();
        $this->assertSame('meeting_reminder', $notification->data['notification_type']);
        $this->assertSame(MeetingReminderWindow::Eve->value, $notification->data['reminder_window']);
        $this->assertSame($meeting->id, $notification->data['meeting_id']);
        $this->assertSame(route('meetings.show', $meeting), $notification->data['action_url']);
        $this->assertNotNull($meeting->fresh()->day_before_reminder_sent_at);
        $sentMessages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(2, $sentMessages);

        foreach ([$todayMeeting, $laterMeeting, $canceledMeeting, $completedMeeting] as $excludedMeeting) {
            $this->assertNull($excludedMeeting->fresh()->day_before_reminder_sent_at);
        }
        $this->assertNotNull($alreadyRemindedMeeting->fresh()->day_before_reminder_sent_at);
    }

    public function test_one_hour_before_window_notifies_reserved_meetings_in_the_next_minute_range(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00'));
        [$student, $coach, $meeting] = $this->createMeetingAt(now()->addMinutes(59));
        [, , $atOneHourMeeting] = $this->createMeetingAt(now()->addHour());
        [, , $tooSoonMeeting] = $this->createMeetingAt(now()->addMinutes(58));
        [, , $tooLateMeeting] = $this->createMeetingAt(now()->addMinutes(61));
        [, , $canceledMeeting] = $this->createMeetingAt(
            now()->addMinutes(59)->addSeconds(30),
            MeetingStatus::Canceled,
        );

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->assertExitCode(0)
            ->expectsOutput('one_hour_before のリマインダーを 2 件配信しました。');

        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(1, $coach->notifications()->count());
        $this->assertSame(
            'one_hour_before',
            $student->notifications()->firstOrFail()->data['reminder_window'],
        );
        $this->assertNotNull($meeting->fresh()->one_hour_before_reminder_sent_at);
        $this->assertNotNull($atOneHourMeeting->fresh()->one_hour_before_reminder_sent_at);

        foreach ([$tooSoonMeeting, $tooLateMeeting, $canceledMeeting] as $excludedMeeting) {
            $this->assertNull($excludedMeeting->fresh()->one_hour_before_reminder_sent_at);
        }
    }

    public function test_repeated_command_does_not_send_duplicate_reminders(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00'));
        [$student, $coach, $meeting] = $this->createMeetingAt(now()->addDay()->setTime(10, 0));

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])->assertExitCode(0);
        $this->travel(2)->minutes();
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])->assertExitCode(0);

        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(1, $coach->notifications()->count());
        $this->assertNotNull($meeting->fresh()->day_before_reminder_sent_at);
    }

    public function test_window_reminders_are_tracked_independently(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00'));
        [$student, $coach, $meeting] = $this->createMeetingAt(now()->addDay()->setTime(10, 0));

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])->assertExitCode(0);
        $this->travelTo(Carbon::parse('2026-11-04 09:00:00'));
        $meeting->update(['scheduled_at' => now()->addMinutes(59)]);
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->assertExitCode(0);

        $this->assertSame(2, $student->notifications()->count());
        $this->assertSame(2, $coach->notifications()->count());
        $this->assertNotNull($meeting->fresh()->day_before_reminder_sent_at);
        $this->assertNotNull($meeting->fresh()->one_hour_before_reminder_sent_at);
    }

    public function test_ineligible_parties_are_not_notified_but_meeting_is_marked_processed(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00'));
        $student = User::factory()->student()->graduated()->create();
        $coach = User::factory()->coach()->withdrawn()->create();
        $meeting = Meeting::factory()->reserved()->forStudent($student)->forCoach($coach)->create([
            'scheduled_at' => now()->addDay()->setTime(10, 0),
        ]);

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->assertExitCode(0)
            ->expectsOutput('eve のリマインダーを 1 件配信しました。');

        $this->assertSame(0, $student->notifications()->count());
        $this->assertSame(0, $coach->notifications()->count());
        $this->assertNotNull($meeting->fresh()->day_before_reminder_sent_at);
    }

    public function test_invalid_window_is_rejected_without_processing_meetings(): void
    {
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'invalid'])
            ->assertExitCode(2)
            ->expectsOutput('オプション --window には eve または one_hour_before を指定してください。');
    }

    public function test_reminder_commands_are_scheduled_at_expected_intervals(): void
    {
        $events = app(Schedule::class)->events();

        $eveEvent = collect($events)->first(
            fn ($event): bool => str_contains($event->command, '--window=eve'),
        );
        $oneHourEvent = collect($events)->first(
            fn ($event): bool => str_contains($event->command, '--window=one_hour_before'),
        );

        $this->assertNotNull($eveEvent);
        $this->assertSame('0 9 * * *', $eveEvent->expression);
        $this->assertNotNull($oneHourEvent);
        $this->assertSame('* * * * *', $oneHourEvent->expression);
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array{User, User, Meeting}
     */
    private function createMeetingAt(
        Carbon $scheduledAt,
        MeetingStatus $status = MeetingStatus::Reserved,
        array $attributes = [],
    ): array {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $meeting = Meeting::factory()
            ->reserved()
            ->forStudent($student)
            ->forCoach($coach)
            ->create(array_merge([
                'scheduled_at' => $scheduledAt,
                'status' => $status->value,
                'completed_at' => $status === MeetingStatus::Completed ? $scheduledAt->copy()->addHour() : null,
                'canceled_at' => $status === MeetingStatus::Canceled ? now() : null,
            ], $attributes));

        return [$student, $coach, $meeting];
    }
}
