<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiChatConversation;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiChatConversation>
 */
class AiChatConversationFactory extends Factory
{
    protected $model = AiChatConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
            'enrollment_id' => null,
            'section_id' => null,
            'title' => '新しい相談',
            'auto_title_enabled' => true,
            'last_message_at' => null,
        ];
    }

    public function withEnrollment(Enrollment $enrollment): static
    {
        return $this->state(fn () => [
            'user_id' => $enrollment->user_id,
            'enrollment_id' => $enrollment->id,
        ]);
    }

    public function forSection(Section $section): static
    {
        return $this->state(fn () => ['section_id' => $section->id]);
    }
}
