<?php

declare(strict_types=1);

namespace App\UseCases\Enrollment;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;

/**
 * Enrollment 詳細取得 Action。受講生 / コーチ / admin 共通(認可は Controller の Policy で済ませる前提)。
 *
 * 詳細ビューで必要な coaches / 修了証 / 最新の状態遷移ログを eager load する。
 */
final class ShowAction
{
    public function __invoke(Enrollment $enrollment, ?User $viewer = null): Enrollment
    {
        $enrollment->loadMissing([
            'certification.category',
            'certification.coaches',
            'certificate',
            'latestStatusLog.changedBy',
            'goals' => fn ($query) => $query->displayOrder(),
        ]);

        if ($viewer?->can('viewAny', [EnrollmentNote::class, $enrollment])) {
            $enrollment->load([
                'notes' => fn ($query) => $query->with('author')->orderByDesc('created_at'),
            ]);

            foreach ($enrollment->notes as $note) {
                $note->setRelation('enrollment', $enrollment);
            }
        }

        return $enrollment;
    }
}
