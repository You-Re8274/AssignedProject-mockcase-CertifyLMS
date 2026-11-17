<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Services\ActivityNotificationService;
use App\Services\CoachMeetingLoadService;
use App\Services\GoogleCalendarService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Carbon\Carbon;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class ReserveMeetingAction
{
    public function __construct(
        private readonly MeetingAvailabilityService $availability,
        private readonly CoachMeetingLoadService $coachLoad,
        private readonly MeetingQuotaService $quota,
        private readonly ConsumeQuotaAction $consumeQuota,
        private readonly ActivityNotificationService $notifications,
        private readonly GoogleCalendarService $googleCalendar,
    ) {}

    public function __invoke(Enrollment $enrollment, Carbon $scheduledAt, string $topic): Meeting
    {
        $student = $enrollment->user;

        return DB::transaction(function () use ($enrollment, $student, $scheduledAt, $topic): Meeting {
            if ($this->quota->remaining($student) < 1) {
                throw new InsufficientMeetingQuotaException;
            }

            $certification = $enrollment->certification;
            $this->availability->validateSlot($certification, $scheduledAt);

            $candidates = $this->findAvailableCoaches($certification, $scheduledAt);
            if ($candidates->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            $candidates = $this->availability->filterCalendarBusyCoaches($candidates, $scheduledAt);
            if ($candidates->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            $coach = $this->coachLoad->leastLoadedCoach($candidates);

            try {
                $meeting = Meeting::create([
                    'enrollment_id' => $enrollment->id,
                    'coach_id' => $coach->id,
                    'student_id' => $student->id,
                    'scheduled_at' => $scheduledAt,
                    'status' => MeetingStatus::Reserved->value,
                    'topic' => $topic,
                    'meeting_url_snapshot' => $coach->meeting_url,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                throw new MeetingNoAvailableCoachException($exception);
            }

            $transaction = ($this->consumeQuota)($student, $meeting->id);
            $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);
            $meeting = $meeting->fresh();

            try {
                $this->googleCalendar->createMeetingEvent($meeting);
            } catch (GoogleServiceException|GuzzleException|RuntimeException $exception) {
                Log::warning('Google Calendar event creation failed; LMS meeting reservation remains active.', [
                    'meeting_id' => $meeting->id,
                    'coach_id' => $meeting->coach_id,
                    'exception' => $exception::class,
                ]);
            }

            $meeting->loadMissing(['student', 'coach']);
            $this->notifications->send(
                recipient: $meeting->coach,
                actor: $student,
                type: 'meeting_reserved',
                title: '新しい面談予約があります',
                message: $student->name.' さんが面談を予約しました。',
                actionUrl: route('meetings.show', $meeting),
            );

            return $meeting;
        });
    }

    /**
     * @return Collection<int, User>
     */
    private function findAvailableCoaches(Certification $certification, Carbon $scheduledAt): Collection
    {
        $time = $scheduledAt->format('H:i:s');

        return $certification->coaches()
            ->whereHas('coachAvailabilities', function ($query) use ($scheduledAt, $time): void {
                $query->where('day_of_week', $scheduledAt->dayOfWeek)
                    ->where('is_active', true)
                    ->where('start_time', '<=', $time)
                    ->where('end_time', '>', $time);
            })
            ->whereDoesntHave('meetingsAsCoach', function ($query) use ($scheduledAt): void {
                $query->where('scheduled_at', $scheduledAt)
                    ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value]);
            })
            ->get();
    }
}
