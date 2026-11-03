<?php

declare(strict_types=1);

namespace App\Enums;

enum MeetingReminderWindow: string
{
    case Eve = 'eve';
    case OneHourBefore = 'one_hour_before';

    public function sentAtColumn(): string
    {
        return match ($this) {
            self::Eve => 'day_before_reminder_sent_at',
            self::OneHourBefore => 'one_hour_before_reminder_sent_at',
        };
    }
}
