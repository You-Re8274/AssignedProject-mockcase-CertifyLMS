<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;

final class ShowMeetingAction
{
    public function __invoke(Meeting $meeting): Meeting
    {
        return $meeting->loadMissing([
            'enrollment.certification',
            'coach',
            'student',
            'canceledBy',
            'meetingMemo',
        ]);
    }
}
