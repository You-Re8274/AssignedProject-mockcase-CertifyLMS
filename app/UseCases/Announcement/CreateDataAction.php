<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class CreateDataAction
{
    /**
     * @return array{certifications: Collection<int, Certification>, students: Collection<int, User>}
     */
    public function __invoke(): array
    {
        return [
            'certifications' => Certification::query()->orderBy('name')->get(),
            'students' => User::query()
                ->where('role', UserRole::Student->value)
                ->where('status', UserStatus::InProgress->value)
                ->orderBy('name')
                ->get(),
        ];
    }
}
