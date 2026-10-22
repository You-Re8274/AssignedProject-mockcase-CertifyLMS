<?php

declare(strict_types=1);

namespace App\UseCases\QaBoard;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class AdminIndexAction
{
    /**
     * @param array{certification_id?: ?string, status?: ?string, keyword?: ?string} $filters
     *
     * @return array{threads: LengthAwarePaginator, certifications: Collection<int, Certification>}
     */
    public function __invoke(array $filters): array
    {
        $certifications = Certification::query()
            ->orderBy('name')
            ->get();

        $threads = QaThread::query()
            ->with(['user', 'certification'])
            ->withCount('replies')
            ->when(
                $filters['certification_id'] ?? null,
                fn (Builder $query, string $certificationId) => $query->where('certification_id', $certificationId),
            )
            ->when(
                ($filters['status'] ?? null) === 'unresolved',
                fn (Builder $query) => $query->where('status', QaThreadStatus::Open->value),
            )
            ->when(
                ($filters['status'] ?? null) === 'resolved',
                fn (Builder $query) => $query->where('status', QaThreadStatus::Resolved->value),
            )
            ->when(
                $filters['keyword'] ?? null,
                fn (Builder $query, string $keyword) => $query->where(function (Builder $search) use ($keyword): void {
                    $search->where('title', 'like', '%'.$keyword.'%')
                        ->orWhere('body', 'like', '%'.$keyword.'%');
                }),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return [
            'threads' => $threads,
            'certifications' => $certifications,
        ];
    }
}
