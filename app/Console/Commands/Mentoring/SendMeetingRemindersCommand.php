<?php

declare(strict_types=1);

namespace App\Console\Commands\Mentoring;

use App\Enums\MeetingReminderWindow;
use App\UseCases\MeetingReminder\FetchCandidatesAction;
use App\UseCases\MeetingReminder\SendReminderAction;
use Illuminate\Console\Command;
use ValueError;

class SendMeetingRemindersCommand extends Command
{
    protected $signature = 'notifications:send-meeting-reminders {--window= : Reminder window (eve or one_hour_before)}';

    protected $description = '予約済み面談の前日・1時間前リマインダーを配信する';

    public function handle(FetchCandidatesAction $fetchCandidates, SendReminderAction $sendReminder): int
    {
        $windowValue = $this->option('window');

        try {
            $window = MeetingReminderWindow::from(is_string($windowValue) ? $windowValue : '');
        } catch (ValueError) {
            $this->error('オプション --window には eve または one_hour_before を指定してください。');

            return self::INVALID;
        }

        $meetings = $fetchCandidates($window);
        $sentCount = 0;

        foreach ($meetings as $meeting) {
            if ($sendReminder($meeting, $window)) {
                $sentCount++;
            }
        }

        $this->info("{$window->value} のリマインダーを {$sentCount} 件配信しました。");

        return self::SUCCESS;
    }
}
