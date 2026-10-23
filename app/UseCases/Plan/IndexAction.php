<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class IndexAction
{
    public function __invoke(?string $keyword, ?PlanStatus $status, int $perPage = 15): LengthAwarePaginator
    {
        $query = Plan::query()
            ->withCount('users')
            ->when(
                $keyword,
                fn ($query, string $value) => $query->where('name', 'like', '%'.$value.'%'),
            )
            ->when(
                $status,
                fn ($query, PlanStatus $value) => $query->where('status', $value),
            )
            ->ordered();

        return $query->paginate($perPage)->withQueryString();
    }
}
