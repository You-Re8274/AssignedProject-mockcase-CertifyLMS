<?php

declare(strict_types=1);

namespace App\UseCases\MeetingReminder;

use App\Enums\MeetingReminderWindow;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use Illuminate\Database\Eloquent\Collection;

final class FetchCandidatesAction
{
    /**
     * @return Collection<int, Meeting>
     */
    public function __invoke(MeetingReminderWindow $window): Collection
    {
        $now = now();
        $query = Meeting::query()
            ->where('status', MeetingStatus::Reserved->value)
            ->whereNull($window->sentAtColumn());

        if ($window === MeetingReminderWindow::Eve) {
            $start = $now->copy()->addDay()->startOfDay();
            $end = $start->copy()->endOfDay();
        } else {
            $start = $now->copy()->addMinutes(59);
            $end = $now->copy()->addHour();
        }

        return $query
            ->whereBetween('scheduled_at', [
                $start->toDateTimeString(),
                $end->toDateTimeString(),
            ])
            ->with(['student', 'coach'])
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();
    }
}
