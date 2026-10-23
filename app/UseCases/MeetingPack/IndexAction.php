<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class IndexAction
{
    public function __invoke(?string $keyword, ?MeetingPackStatus $status, int $perPage = 15): LengthAwarePaginator
    {
        $query = MeetingPack::query()
            ->when(
                $keyword,
                fn ($query, string $value) => $query->where('name', 'like', '%'.$value.'%'),
            )
            ->when(
                $status,
                fn ($query, MeetingPackStatus $value) => $query->where('status', $value),
            )
            ->ordered();

        return $query->paginate($perPage)->withQueryString();
    }
}
