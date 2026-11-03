<?php

declare(strict_types=1);

namespace App\UseCases\MeetingReminder;

use App\Enums\MeetingReminderWindow;
use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Facades\DB;

final class SendReminderAction
{
    public function __invoke(Meeting $meeting, MeetingReminderWindow $window): bool
    {
        return DB::transaction(function () use ($meeting, $window): bool {
            $lockedMeeting = Meeting::query()
                ->whereKey($meeting->getKey())
                ->lockForUpdate()
                ->with(['student', 'coach'])
                ->first();

            if ($lockedMeeting === null
                || $lockedMeeting->status !== MeetingStatus::Reserved
                || $lockedMeeting->{$window->sentAtColumn()} !== null) {
                return false;
            }

            foreach ([$lockedMeeting->student, $lockedMeeting->coach] as $recipient) {
                if ($this->isEligibleRecipient($recipient)) {
                    $recipient->notify($this->notification($lockedMeeting, $window));
                }
            }

            $lockedMeeting->forceFill([
                $window->sentAtColumn() => now(),
            ])->save();

            return true;
        });
    }

    private function isEligibleRecipient(?User $recipient): bool
    {
        return $recipient !== null
            && $recipient->status === UserStatus::InProgress
            && in_array($recipient->role, [UserRole::Student, UserRole::Coach], true);
    }

    private function notification(Meeting $meeting, MeetingReminderWindow $window): ActivityNotification
    {
        $time = $meeting->scheduled_at->format('Y/m/d H:i');
        $reminderLabel = $window === MeetingReminderWindow::Eve ? '前日' : '1時間前';
        $title = "面談の{$reminderLabel}リマインダー";
        $message = "予約済みの面談は {$time} 開始予定です。";

        return new ActivityNotification([
            'notification_type' => 'meeting_reminder',
            'meeting_id' => $meeting->id,
            'reminder_window' => $window->value,
            'title' => $title,
            'message' => $message,
            'body' => $message,
            'action_url' => route('meetings.show', $meeting),
        ]);
    }
}
