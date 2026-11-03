<?php

declare(strict_types=1);

namespace App\Console\Commands\Mentoring;

use App\Enums\MeetingReminderWindow;
use App\UseCases\MeetingReminder\FetchCandidatesAction;
use Illuminate\Console\Command;
use ValueError;

class SendMeetingRemindersCommand extends Command
{
    protected $signature = 'notifications:send-meeting-reminders {--window= : Reminder window (eve or one_hour_before)}';

    protected $description = '予約済み面談のリマインダー候補を抽出する';

    public function handle(FetchCandidatesAction $action): int
    {
        $windowValue = $this->option('window');

        try {
            $window = MeetingReminderWindow::from(is_string($windowValue) ? $windowValue : '');
        } catch (ValueError) {
            $this->error('オプション --window には eve または one_hour_before を指定してください。');

            return self::INVALID;
        }

        $meetings = $action($window);
        $this->info("{$window->value} のリマインダー候補は {$meetings->count()} 件です。");

        return self::SUCCESS;
    }
}
