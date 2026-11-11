<?php

declare(strict_types=1);

namespace Tests\Feature\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Enums\ContentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\AiChatSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiChatSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_contextual_general_and_failed_conversations_idempotently(): void
    {
        $student = User::factory()->student()->create([
            'email' => 'student@certify-lms.test',
        ]);
        $enrollment = Enrollment::factory()->for($student)->create([
            'status' => EnrollmentStatus::Learning->value,
        ]);
        $part = Part::factory()
            ->published()
            ->forCertification($enrollment->certification)
            ->create();
        $chapter = Chapter::factory()->published()->forPart($part)->create();
        Section::factory()->published()->forChapter($chapter)->create();

        $this->seed(AiChatSeeder::class);
        $this->seed(AiChatSeeder::class);

        $conversations = AiChatConversation::query()->where('user_id', $student->id)->get();
        $messages = AiChatMessage::query()
            ->whereIn('ai_chat_conversation_id', $conversations->modelKeys())
            ->get();

        $this->assertCount(3, $conversations);
        $this->assertTrue($conversations->contains(fn (AiChatConversation $conversation) => $conversation->section_id !== null));
        $this->assertTrue($conversations->contains(fn (AiChatConversation $conversation) => $conversation->section_id === null));
        $this->assertTrue($messages->contains(
            fn (AiChatMessage $message) => $message->role === AiChatMessageRole::Assistant
                && $message->status === AiChatMessageStatus::Error,
        ));
        $this->assertSame(6, $messages->count());
        $this->assertDatabaseHas('ai_chat_conversations', [
            'id' => $conversations->firstWhere('section_id', '!=', null)->id,
            'enrollment_id' => $enrollment->id,
            'section_id' => Section::query()
                ->whereHas('chapter.part', fn ($query) => $query->where('certification_id', $enrollment->certification_id))
                ->where('status', ContentStatus::Published->value)
                ->value('id'),
        ]);
    }
}
