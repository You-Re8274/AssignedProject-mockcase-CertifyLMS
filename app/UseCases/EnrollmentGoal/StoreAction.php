<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;

final class StoreAction
{
    /**
     * @param array{title: string, description?: ?string, target_date?: ?string} $data
     */
    public function __invoke(Enrollment $enrollment, array $data): EnrollmentGoal
    {
        return $enrollment->goals()->create($data);
    }
}
