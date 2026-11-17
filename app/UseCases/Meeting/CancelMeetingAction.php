<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\User;
use App\Services\ActivityNotificationService;
use App\Services\GoogleCalendarService;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class CancelMeetingAction
{
    public function __construct(
        private readonly RefundQuotaAction $refundQuota,
        private readonly ActivityNotificationService $notifications,
        private readonly GoogleCalendarService $googleCalendar,
    ) {}

    public function __invoke(Meeting $meeting, User $actor): void
    {
        DB::transaction(function () use ($meeting, $actor): void {
            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== MeetingStatus::Reserved) {
                throw MeetingStatusTransitionException::forCancel();
            }

            if ($locked->scheduled_at->lessThanOrEqualTo(now())) {
                throw new MeetingAlreadyStartedException;
            }

            $locked->update([
                'status' => MeetingStatus::Canceled->value,
                'canceled_by_user_id' => $actor->id,
                'canceled_at' => now(),
            ]);

            ($this->refundQuota)($locked->student()->firstOrFail(), $locked->id);
            $locked = $locked->fresh();

            try {
                $this->googleCalendar->deleteMeetingEvent($locked);
            } catch (GoogleServiceException|GuzzleException|RuntimeException $exception) {
                Log::warning('Google Calendar event deletion failed; LMS meeting cancellation remains active.', [
                    'meeting_id' => $locked->id,
                    'coach_id' => $locked->coach_id,
                    'exception' => $exception::class,
                ]);
            }

            $locked->loadMissing(['student', 'coach']);
            foreach ([$locked->student, $locked->coach] as $recipient) {
                $this->notifications->send(
                    recipient: $recipient,
                    actor: $actor,
                    type: 'meeting_canceled',
                    title: '面談がキャンセルされました',
                    message: $actor->name.' さんが面談をキャンセルしました。',
                    actionUrl: route('meetings.show', $locked),
                );
            }
        });
    }
}
