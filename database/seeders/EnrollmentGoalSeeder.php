<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

final class EnrollmentGoalSeeder extends Seeder
{
    public function run(): void
    {
        $fixedStudent = User::query()
            ->where('email', 'student@certify-lms.test')
            ->first();

        if ($fixedStudent !== null) {
            $enrollment = $fixedStudent->enrollments()
                ->orderBy('created_at')
                ->first();

            if ($enrollment !== null) {
                $this->seedGoal(
                    $enrollment,
                    '過去問を5年分解き終える',
                    '毎週末に1年分ずつ取り組み、間違えた問題を復習する。',
                    now()->addWeeks(4)->toDateString(),
                    null,
                );
                $this->seedGoal(
                    $enrollment,
                    '基礎テキストを一周する',
                    '苦手な章を記録しながら学習する。',
                    now()->subDays(3)->toDateString(),
                    now()->subDays(2),
                );
            }
        }

        $demoEnrollments = Enrollment::query()
            ->whereHas('user', fn ($query) => $query
                ->where('role', UserRole::Student->value)
                ->where('email', '!=', 'student@certify-lms.test'))
            ->with('user')
            ->orderBy('id')
            ->get();

        foreach ($demoEnrollments as $index => $enrollment) {
            $achievedAt = $index % 2 === 0 ? null : now()->subDays($index + 1);
            $this->seedGoal(
                $enrollment,
                '学習計画 '.$enrollment->certification_id,
                $enrollment->user->name.' さんの資格学習目標です。',
                now()->addDays(14 + ($index % 30))->toDateString(),
                $achievedAt,
            );
        }
    }

    private function seedGoal(
        Enrollment $enrollment,
        string $title,
        string $description,
        string $targetDate,
        ?Carbon $achievedAt,
    ): void {
        EnrollmentGoal::query()->firstOrCreate(
            [
                'enrollment_id' => $enrollment->id,
                'title' => $title,
            ],
            [
                'description' => $description,
                'target_date' => $targetDate,
                'achieved_at' => $achievedAt,
            ],
        );
    }
}
