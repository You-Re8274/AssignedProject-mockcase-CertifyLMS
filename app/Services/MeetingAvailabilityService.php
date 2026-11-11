<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Meeting;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 担当コーチ集合の面談可能時間枠を 60 分単位で展開し、空きスロットを集計する Service。
 *
 * 受講生の予約画面が「該当資格の担当コーチ全員の有効枠 Union」を 1 日単位で取得し、
 * LMS 内の予約と連携済みコーチの Google busy 区間を除外して各スロットの予約可能人数を返す。
 * Google API 障害時はログを記録し、そのコーチは LMS 内の予約だけで判定する。
 */
final class MeetingAvailabilityService
{
    /**
     * @var array<string, array<int, array{start: CarbonImmutable, end: CarbonImmutable}>>
     */
    private array $busyIntervalsByCoachAndDate = [];

    public function __construct(
        private readonly GoogleCalendarService $googleCalendar,
    ) {}

    /**
     * 指定 Certification の担当コーチ集合について、指定日 1 日分の 60 分単位空きスロットを返す。
     *
     * LMS 側は availability 1 クエリ + meetings 1 クエリで取得し、連携中コーチのみ Google API を照会する。
     *
     * @return Collection<int, array{slot_start: Carbon, slot_end: Carbon, available_coach_count: int}>
     */
    public function slotsForCertification(Certification $certification, Carbon $date): Collection
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();
        $dayOfWeek = $date->dayOfWeek;

        $coaches = $certification->coaches()->get();
        if ($coaches->isEmpty()) {
            return collect();
        }

        $coachIds = $coaches->pluck('id')->all();

        $availabilities = CoachAvailability::query()
            ->whereIn('coach_id', $coachIds)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->get();

        $existingMeetings = Meeting::query()
            ->whereIn('coach_id', $coachIds)
            ->whereBetween('scheduled_at', [$dayStart, $dayEnd])
            ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value])
            ->get(['coach_id', 'scheduled_at']);

        // 予約済スロットを (coach_id => Set<H:i>) で索引化
        $bookedByCoach = $existingMeetings
            ->groupBy('coach_id')
            ->map(fn ($rows) => $rows->map(fn (Meeting $m) => $m->scheduled_at->format('H:i'))->all());

        /** @var array<string, int> $slotCounts スロット開始時刻(H:i) → available coach 数 */
        $slotCounts = [];

        foreach ($availabilities as $availability) {
            $slot = Carbon::parse($date->format('Y-m-d').' '.$availability->start_time);
            $end = Carbon::parse($date->format('Y-m-d').' '.$availability->end_time);
            $coach = $coaches->firstWhere('id', $availability->coach_id);

            while ($slot->copy()->addHour() <= $end) {
                $slotKey = $slot->format('H:i');
                $coachId = $availability->coach_id;
                $booked = $bookedByCoach[$coachId] ?? [];
                $slotEnd = $slot->copy()->addHour();

                if (! in_array($slotKey, $booked, true)
                    && ($coach === null || ! $this->isCalendarBusy($coach, $slot, $slotEnd))) {
                    $slotCounts[$slotKey] = ($slotCounts[$slotKey] ?? 0) + 1;
                }

                $slot->addHour();
            }
        }

        ksort($slotCounts);

        return collect($slotCounts)->map(function (int $count, string $time) use ($date) {
            $start = Carbon::parse($date->format('Y-m-d').' '.$time);

            return [
                'slot_start' => $start,
                'slot_end' => $start->copy()->addHour(),
                'available_coach_count' => $count,
            ];
        })->values();
    }

    /**
     * 指定枠のGoogle primary calendarがbusyなコーチを除外する。
     * API障害時は記録を残し、従来どおりLMS内の予約だけで判定する。
     *
     * @param Collection<int, User> $coaches
     *
     * @return Collection<int, User>
     */
    public function filterCalendarBusyCoaches(Collection $coaches, Carbon $slotStart): Collection
    {
        $slotEnd = $slotStart->copy()->addHour();

        return $coaches
            ->reject(fn (User $coach) => $this->isCalendarBusy($coach, $slotStart, $slotEnd))
            ->values();
    }

    /**
     * 指定 scheduled_at が certification 担当コーチ集合の有効枠内かを検証する。
     * 枠外なら MeetingOutOfAvailabilityException を throw する。
     *
     * @throws MeetingOutOfAvailabilityException
     */
    public function validateSlot(Certification $certification, Carbon $scheduledAt): void
    {
        $slots = $this->slotsForCertification($certification, $scheduledAt->copy()->startOfDay());

        $matched = $slots->contains(
            fn (array $slot) => $slot['slot_start']->equalTo($scheduledAt) && $slot['available_coach_count'] > 0,
        );

        if (! $matched) {
            throw new MeetingOutOfAvailabilityException;
        }
    }

    private function isCalendarBusy(User $coach, Carbon $slotStart, Carbon $slotEnd): bool
    {
        if (! filled($coach->google_access_token) && ! filled($coach->google_refresh_token)) {
            return false;
        }

        $cacheKey = $coach->id.':'.$slotStart->toDateString();
        if (! array_key_exists($cacheKey, $this->busyIntervalsByCoachAndDate)) {
            try {
                $this->busyIntervalsByCoachAndDate[$cacheKey] = $this->googleCalendar->busyIntervals(
                    $coach,
                    $slotStart->copy()->startOfDay(),
                    $slotStart->copy()->endOfDay(),
                );
            } catch (GoogleServiceException|GuzzleException|RuntimeException $exception) {
                Log::warning('Google Calendar availability lookup failed; using LMS availability only.', [
                    'coach_id' => $coach->id,
                    'date' => $slotStart->toDateString(),
                    'exception' => $exception::class,
                ]);
                $this->busyIntervalsByCoachAndDate[$cacheKey] = [];
            }
        }

        foreach ($this->busyIntervalsByCoachAndDate[$cacheKey] as $interval) {
            if ($interval['start']->lessThan($slotEnd) && $interval['end']->greaterThan($slotStart)) {
                return true;
            }
        }

        return false;
    }
}
