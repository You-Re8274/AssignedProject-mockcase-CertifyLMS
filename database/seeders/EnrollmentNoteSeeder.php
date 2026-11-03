<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Database\Seeder;

final class EnrollmentNoteSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()
            ->where('role', UserRole::Admin->value)
            ->orderBy('created_at')
            ->first();

        $enrollments = Enrollment::query()
            ->with('certification.coaches')
            ->orderBy('created_at')
            ->get();

        foreach ($enrollments as $index => $enrollment) {
            foreach ($enrollment->certification->coaches as $coach) {
                $this->seedNote(
                    $enrollment,
                    $coach,
                    "定期フォロー: {$coach->name} が担当資格の進捗を確認しました。",
                );
            }

            if ($admin !== null && $index % 2 === 0) {
                $this->seedNote(
                    $enrollment,
                    $admin,
                    '運営確認: 次回のフォロー状況を確認してください。',
                );
            }
        }
    }

    private function seedNote(Enrollment $enrollment, User $author, string $body): void
    {
        EnrollmentNote::query()->firstOrCreate([
            'enrollment_id' => $enrollment->id,
            'user_id' => $author->id,
            'body' => $body,
        ]);
    }
}
