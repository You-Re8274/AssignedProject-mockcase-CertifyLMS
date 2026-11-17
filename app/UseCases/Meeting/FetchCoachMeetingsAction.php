<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class FetchCoachMeetingsAction
{
    /**
     * @param array{filter?: string, student?: string, enrollment?: string} $filters
     *
     * @return LengthAwarePaginator<Meeting>
     */
    public function __invoke(User $coach, array $filters): LengthAwarePaginator
    {
        $filter = $filters['filter'] ?? 'upcoming';
        $query = Meeting::query()
            ->with(['enrollment.certification', 'student'])
            ->forCoach($coach)
            ->when($filters['student'] ?? null, fn ($q, $id) => $q->where('student_id', $id))
            ->when($filters['enrollment'] ?? null, fn ($q, $id) => $q->where('enrollment_id', $id));

        return match ($filter) {
            'past' => $query->past()->orderByDesc('scheduled_at')->paginate(20),
            'all' => $query->orderByDesc('scheduled_at')->paginate(20),
            default => $query->upcoming()->orderBy('scheduled_at')->paginate(20),
        };
    }
}
