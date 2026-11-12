<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatDailyUsage;
use App\Models\AiChatMessage;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiChatConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_relations_and_boolean_cast_are_defined(): void
    {
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $section = Section::factory()->published()->create();
        $conversation = AiChatConversation::factory()
            ->withEnrollment($enrollment)
            ->forSection($section)
            ->create(['auto_title_enabled' => 0]);

        $this->assertTrue($conversation->user->is($student));
        $this->assertTrue($conversation->enrollment->is($enrollment));
        $this->assertTrue($conversation->section->is($section));
        $this->assertFalse($conversation->auto_title_enabled);
        $this->assertTrue($student->aiChatConversations()->whereKey($conversation->id)->exists());
    }

    public function test_messages_cast_enums_and_are_ordered_by_creation_time(): void
    {
        $conversation = AiChatConversation::factory()->create();

        $laterMessage = AiChatMessage::factory()
            ->for($conversation, 'conversation')
            ->fromAssistant()
            ->create([
                'status' => AiChatMessageStatus::Error,
                'error_detail' => 'Gemini API unavailable',
                'created_at' => now()->addMinute(),
            ]);
        $earlierMessage = AiChatMessage::factory()
            ->for($conversation, 'conversation')
            ->create([
                'role' => AiChatMessageRole::User,
                'created_at' => now(),
            ]);

        $this->assertSame(AiChatMessageRole::Assistant, $laterMessage->role);
        $this->assertSame(AiChatMessageStatus::Error, $laterMessage->status);
        $this->assertSame('Gemini API unavailable', $laterMessage->error_detail);
        $this->assertSame(
            [$earlierMessage->id, $laterMessage->id],
            $conversation->messages()->pluck('id')->all(),
        );
    }

    public function test_daily_usage_is_related_to_user_and_casts_its_values(): void
    {
        $student = User::factory()->student()->create();
        $usage = AiChatDailyUsage::factory()->for($student)->create([
            'usage_date' => '2026-11-11',
            'request_count' => '3',
        ]);

        $this->assertTrue($usage->user->is($student));
        $this->assertSame('2026-11-11', $usage->usage_date->toDateString());
        $this->assertSame(3, $usage->request_count);
        $this->assertTrue($student->aiChatDailyUsages()->whereKey($usage->id)->exists());
    }
}
