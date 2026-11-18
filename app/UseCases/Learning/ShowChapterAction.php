<?php

declare(strict_types=1);

namespace App\UseCases\Learning;

use App\Enums\CertificationStatus;
use App\Enums\ContentStatus;
use App\Models\Chapter;
use App\Models\User;
use App\Services\Learning\LearningProgressService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /learning/chapters/{chapter} (4 階層目、Section 一覧) のデータを準備する Action。
 *
 * cascade visibility (親資格 / Chapter / Part のいずれかが非公開) で 404、
 * 公開済 Section 一覧と受講生の読了済 Section ID 配列を併せて返す (Section 行の読了バッジ用)。
 */
final class ShowChapterAction
{
    public function __construct(
        private readonly LearningProgressService $progressService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Chapter $chapter, User $student): array
    {
        $chapter->loadMissing('part.certification');

        if ($chapter->status !== ContentStatus::Published
            || $chapter->part === null
            || $chapter->part->status !== ContentStatus::Published
            || $chapter->part->certification?->status !== CertificationStatus::Published) {
            throw new NotFoundHttpException;
        }

        $sections = $chapter->sections()
            ->where('status', ContentStatus::Published->value)
            ->ordered()
            ->get();

        $enrollment = $student->enrollments()
            ->where('certification_id', $chapter->part->certification_id)
            ->first();

        $completedSectionIds = $enrollment !== null
            ? $this->progressService->completedSectionIds($enrollment, $sections->pluck('id'))
            : [];

        return [
            'chapter' => $chapter,
            'sections' => $sections,
            'completedSectionIds' => $completedSectionIds,
        ];
    }
}
