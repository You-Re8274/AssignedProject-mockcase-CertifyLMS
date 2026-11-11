<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Enums\ContentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;

final class AiChatSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::query()->where('email', 'student@certify-lms.test')->first();

        if ($student === null) {
            $this->command?->warn('AiChatSeeder: 固定受講生が存在しません。先に UserSeeder を実行してください。');

            return;
        }

        $enrollment = $student->enrollments()
            ->where('status', EnrollmentStatus::Learning->value)
            ->orderBy('created_at')
            ->first();

        if ($enrollment === null) {
            $this->command?->warn('AiChatSeeder: 固定受講生の受講登録が存在しません。先に EnrollmentSeeder を実行してください。');

            return;
        }

        $section = Section::query()
            ->where('status', ContentStatus::Published->value)
            ->whereHas('chapter', fn ($query) => $query
                ->where('status', ContentStatus::Published->value)
                ->whereHas('part', fn ($partQuery) => $partQuery
                    ->where('status', ContentStatus::Published->value)
                    ->where('certification_id', $enrollment->certification_id)))
            ->orderBy('created_at')
            ->first();

        if ($section === null) {
            $this->command?->warn('AiChatSeeder: 受講資格の公開教材が存在しないため、教材に紐づく会話をスキップします。');
        } else {
            $conversation = $this->conversation(
                $student,
                $enrollment,
                $section,
                '2進数の表現について',
                daysAgo: 3,
            );
            $this->message(
                $conversation,
                AiChatMessageRole::User,
                AiChatMessageStatus::Completed,
                '2進数から10進数へ変換する手順を確認したいです。',
                daysAgo: 3,
                hoursAgo: 2,
            );
            $this->message(
                $conversation,
                AiChatMessageRole::Assistant,
                AiChatMessageStatus::Completed,
                '各桁の値に、右端から順に2の0乗、2の1乗という重みを掛けて合計します。',
                daysAgo: 3,
                hoursAgo: 2,
                minutesAgo: 1,
                metadata: [
                    'model_name' => 'gemini-example',
                    'input_tokens' => 145,
                    'output_tokens' => 42,
                    'response_time_ms' => 820,
                ],
            );
        }

        $generalConversation = $this->conversation(
            $student,
            $enrollment,
            null,
            '学習計画の相談',
            daysAgo: 1,
        );
        $this->message(
            $generalConversation,
            AiChatMessageRole::User,
            AiChatMessageStatus::Completed,
            '仕事と両立しながら資格学習を続けるコツを教えてください。',
            daysAgo: 1,
            hoursAgo: 1,
        );
        $this->message(
            $generalConversation,
            AiChatMessageRole::Assistant,
            AiChatMessageStatus::Completed,
            '短時間でも毎日取り組める時間を決め、週ごとに学習内容を振り返る方法がおすすめです。',
            daysAgo: 1,
            hoursAgo: 1,
            minutesAgo: 1,
            metadata: [
                'model_name' => 'gemini-example',
                'input_tokens' => 110,
                'output_tokens' => 38,
                'response_time_ms' => 760,
            ],
        );

        $failedConversation = $this->conversation(
            $student,
            $enrollment,
            null,
            '回答を再試行できる相談',
            daysAgo: 0,
        );
        $this->message(
            $failedConversation,
            AiChatMessageRole::User,
            AiChatMessageStatus::Completed,
            '暗記した内容を忘れにくくする復習方法はありますか？',
            daysAgo: 0,
            hoursAgo: 1,
        );
        $this->message(
            $failedConversation,
            AiChatMessageRole::Assistant,
            AiChatMessageStatus::Error,
            '',
            daysAgo: 0,
            minutesAgo: 59,
            metadata: ['error_detail' => 'Gemini API 503: temporary service unavailable'],
        );
    }

    private function conversation(
        User $student,
        Enrollment $enrollment,
        ?Section $section,
        string $title,
        int $daysAgo,
    ): AiChatConversation {
        $createdAt = now()->subDays($daysAgo)->startOfDay()->addHours(9);

        $conversation = AiChatConversation::query()->firstOrCreate(
            ['user_id' => $student->id, 'title' => $title],
            [
                'enrollment_id' => $enrollment->id,
                'section_id' => $section?->id,
                'auto_title_enabled' => true,
                'last_message_at' => $createdAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ],
        );

        return $conversation;
    }

    /**
     * @param array<string, int|string|null> $metadata
     */
    private function message(
        AiChatConversation $conversation,
        AiChatMessageRole $role,
        AiChatMessageStatus $status,
        string $content,
        int $daysAgo,
        int $hoursAgo = 0,
        int $minutesAgo = 0,
        array $metadata = [],
    ): void {
        $createdAt = now()->subDays($daysAgo)->subHours($hoursAgo)->subMinutes($minutesAgo);

        AiChatMessage::query()->firstOrCreate(
            [
                'ai_chat_conversation_id' => $conversation->id,
                'role' => $role->value,
                'status' => $status->value,
                'content' => $content,
            ],
            array_merge($metadata, [
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]),
        );

        if ($conversation->last_message_at === null || $createdAt->greaterThan($conversation->last_message_at)) {
            $conversation->forceFill(['last_message_at' => $createdAt])->save();
        }
    }
}
