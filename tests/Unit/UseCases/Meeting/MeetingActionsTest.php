<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\GoogleCalendarService;
use App\UseCases\Meeting\CancelMeetingAction;
use App\UseCases\Meeting\FetchAvailabilityAction;
use App\UseCases\Meeting\FetchCoachMeetingsAction;
use App\UseCases\Meeting\FetchStudentMeetingsAction;
use App\UseCases\Meeting\ReserveMeetingAction;
use App\UseCases\Meeting\SaveMeetingMemoAction;
use App\UseCases\Meeting\ShowMeetingAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MeetingActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_student_meetings_action_returns_owned_upcoming_meetings_and_quota(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $meeting = Meeting::factory()->reserved()->forStudent($student)->inFuture()->create();

        $data = app(FetchStudentMeetingsAction::class)($student, 'upcoming');

        $this->assertSame([$meeting->id], $data['meetings']->getCollection()->pluck('id')->all());
        $this->assertSame(3, $data['meetingsRemaining']);
        $this->assertTrue($data['meetings']->first()->relationLoaded('coach'));
        $this->assertTrue($data['meetings']->first()->enrollment->relationLoaded('certification'));
    }

    public function test_fetch_coach_meetings_action_applies_filter_and_orders_upcoming_first(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $later = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->inFuture()
            ->create(['scheduled_at' => now()->addDays(3)->startOfHour()]);
        $earlier = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->inFuture()
            ->create(['scheduled_at' => now()->addDays(1)->startOfHour()]);
        Meeting::factory()->reserved()->forStudent($student)->inFuture()->create();

        $meetings = app(FetchCoachMeetingsAction::class)($coach, [
            'filter' => 'upcoming',
            'student' => $student->id,
        ]);

        $this->assertSame(
            [$earlier->id, $later->id],
            $meetings->getCollection()->pluck('id')->all(),
        );
        $this->assertTrue($meetings->first()->relationLoaded('student'));
        $this->assertTrue($meetings->first()->enrollment->relationLoaded('certification'));
    }

    public function test_show_meeting_action_loads_detail_relations(): void
    {
        $meeting = Meeting::factory()->reserved()->create();

        $result = app(ShowMeetingAction::class)($meeting);

        foreach (['enrollment', 'coach', 'student', 'canceledBy', 'meetingMemo'] as $relation) {
            $this->assertTrue($result->relationLoaded($relation));
        }
        $this->assertTrue($result->enrollment->relationLoaded('certification'));
    }

    public function test_fetch_availability_action_returns_service_slots_for_enrollment_certification(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '11:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();

        $slots = app(FetchAvailabilityAction::class)(
            $enrollment,
            Carbon::today()->next(Carbon::MONDAY),
        );

        $this->assertCount(2, $slots);
        $this->assertSame(1, $slots->first()['available_coach_count']);
    }

    public function test_reserve_meeting_action_creates_meeting_consumes_quota_and_sends_notification_in_transaction(): void
    {
        Notification::fake();
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '11:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = Carbon::today()->next(Carbon::MONDAY)->setTime(9, 0);

        $calendar = Mockery::mock(GoogleCalendarService::class);
        $calendar->shouldReceive('createMeetingEvent')
            ->once()
            ->withArgs(fn (Meeting $meeting): bool => DB::transactionLevel() > 0
                && $meeting->coach_id === $coach->id);
        $this->app->instance(GoogleCalendarService::class, $calendar);

        $meeting = app(ReserveMeetingAction::class)($enrollment, $scheduledAt, '相談したい');

        $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'type' => MeetingQuotaTransactionType::Consumed->value,
            'related_meeting_id' => $meeting->id,
        ]);
        Notification::assertSentTo($coach, ActivityNotification::class);
    }

    public function test_cancel_meeting_action_refunds_quota_and_deletes_calendar_event_in_transaction(): void
    {
        Notification::fake();
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->inProgress()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(2)->startOfHour(),
            'google_calendar_event_id' => 'event-id',
        ]);
        MeetingQuotaTransaction::factory()->for($student)->consumed($meeting->id)->create();

        $calendar = Mockery::mock(GoogleCalendarService::class);
        $calendar->shouldReceive('deleteMeetingEvent')
            ->once()
            ->withArgs(fn (Meeting $canceling): bool => DB::transactionLevel() > 0
                && $canceling->status === MeetingStatus::Canceled);
        $this->app->instance(GoogleCalendarService::class, $calendar);

        app(CancelMeetingAction::class)($meeting, $student);

        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
            'related_meeting_id' => $meeting->id,
        ]);
        Notification::assertNotSentTo($student, ActivityNotification::class);
        Notification::assertSentTo($coach, ActivityNotification::class);
    }

    public function test_save_meeting_memo_action_upserts_memo(): void
    {
        $meeting = Meeting::factory()->completed()->create();

        app(SaveMeetingMemoAction::class)($meeting, '初回メモ');
        app(SaveMeetingMemoAction::class)($meeting, '更新済みメモ');

        $this->assertSame(1, MeetingMemo::query()->where('meeting_id', $meeting->id)->count());
        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => '更新済みメモ',
        ]);
    }

    private function attachCoach(Certification $certification, User $coach, User $admin): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }
}
