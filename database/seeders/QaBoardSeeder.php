<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

final class QaBoardSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::query()->where('email', 'student@certify-lms.test')->first();
        if ($student === null) {
            $this->command?->warn('QaBoardSeeder: 固定受講生が存在しません。先に UserSeeder を実行してください。');

            return;
        }

        $certifications = Certification::query()
            ->published()
            ->with('coaches')
            ->orderBy('name')
            ->get();

        if ($certifications->isEmpty()) {
            $this->command?->warn('QaBoardSeeder: 公開中の資格が存在しません。先に CertificationSeeder を実行してください。');

            return;
        }

        $otherStudent = User::query()
            ->where('role', UserRole::Student->value)
            ->whereKeyNot($student->id)
            ->orderBy('created_at')
            ->first() ?? $student;

        foreach ($certifications as $certificationIndex => $certification) {
            $coach = $certification->coaches->first();
            $this->seedCertificationThreads($certification, $student, $otherStudent, $coach, $certificationIndex);
        }
    }

    private function seedCertificationThreads(
        Certification $certification,
        User $student,
        User $otherStudent,
        ?User $coach,
        int $certificationIndex,
    ): void {
        $replyCoach = $coach ?? $otherStudent;
        $threadData = [
            [
                'title' => $certification->name.'の学習で最初に確認すべきことは何ですか？',
                'body' => 'この資格の学習を始めました。全体像をつかむために、まず重点的に取り組むべき範囲や教材の使い方を教えてください。',
                'status' => QaThreadStatus::Open,
                'days_ago' => 2 + $certificationIndex,
                'reply_authors' => [],
            ],
            [
                'title' => $certification->name.'の過去問の復習方法について',
                'body' => '過去問を解いたあと、間違えた問題をどのように復習すると知識が定着しやすいでしょうか。おすすめの進め方を知りたいです。',
                'status' => QaThreadStatus::Resolved,
                'days_ago' => 9 + ($certificationIndex * 2),
                'reply_authors' => [$replyCoach, $otherStudent],
            ],
            [
                'title' => $certification->name.'で苦手分野を克服する方法を教えてください',
                'body' => '演習で同じ分野を何度も間違えてしまいます。解説を読んでも理解が曖昧なとき、どのように学習を進めるとよいでしょうか。',
                'status' => QaThreadStatus::Open,
                'days_ago' => 22 + ($certificationIndex * 3),
                'reply_authors' => [$replyCoach],
            ],
        ];

        foreach ($threadData as $threadIndex => $data) {
            $createdAt = Carbon::now()
                ->subDays($data['days_ago'])
                ->setTime(9 + $threadIndex, 0);
            $resolvedAt = $data['status'] === QaThreadStatus::Resolved
                ? $createdAt->copy()->addHours(5)
                : null;

            $thread = QaThread::factory()->create([
                'certification_id' => $certification->id,
                'user_id' => $student->id,
                'title' => $data['title'],
                'body' => $data['body'],
                'status' => $data['status']->value,
                'resolved_at' => $resolvedAt,
            ]);
            $thread->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

            foreach ($data['reply_authors'] as $replyIndex => $replyAuthor) {
                $reply = QaReply::factory()->create([
                    'qa_thread_id' => $thread->id,
                    'user_id' => $replyAuthor->id,
                    'body' => $replyIndex === 0
                        ? 'まずは解説の用語を確認し、間違えた理由を一つずつ整理してみてください。範囲を小さく区切って復習すると進めやすいです。'
                        : '教えていただいた方法で復習したところ、理解が深まりました。ありがとうございます。',
                ]);
                $replyCreatedAt = $createdAt->copy()->addHours($replyIndex + 2);
                $reply->forceFill([
                    'created_at' => $replyCreatedAt,
                    'updated_at' => $replyCreatedAt,
                ])->saveQuietly();
            }
        }
    }
}
