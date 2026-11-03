<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Models\Announcement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class IndexAction
{
    public function __invoke(int $perPage = 20): LengthAwarePaginator
    {
        return Announcement::query()
            ->with(['targetCertification', 'targetUser', 'createdBy'])
            ->latest('dispatched_at')
            ->latest('created_at')
            ->paginate($perPage);
    }
}
