<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatDailyUsage;
use App\Models\AiChatMessage;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiChatControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai-chat.enabled' => true]);
    }

    public function test_feature_flag_and_student_learning_status_are_required(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        config(['ai-chat.enabled' => false]);
        $this->actingAs($student)->get(route('ai-chat.index'))->assertNotFound();

        config(['ai-chat.enabled' => true]);
        $this->actingAs(User::factory()->student()->graduated()->create())
            ->get(route('ai-chat.index'))
            ->assertForbidden();
        $this->actingAs(User::factory()->coach()->inProgress()->create())
            ->get(route('ai-chat.index'))
            ->assertForbidden();
        $this->actingAs(User::factory()->admin()->inProgress()->create())
            ->get(route('ai-chat.index'))
            ->assertForbidden();
    }

    public function test_student_can_create_a_conversation_with_valid_section_context_and_reuse_it(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->create([
            'status' => EnrollmentStatus::Learning->value,
        ]);
        $section = $this->publishedSection($enrollment->certification);
        $this->actingAs($student);

        $this->postJson(route('ai-chat.conversations.store'), [
            'source' => 'widget',
            'section_id' => $section->id,
        ])->assertCreated()
            ->assertJsonPath('conversation.title', '新しい相談');

        $this->postJson(route('ai-chat.conversations.store'), [
            'source' => 'widget',
            'section_id' => $section->id,
        ])->assertOk()
            ->assertJsonPath('conversation.id', AiChatConversation::query()->value('id'));

        $conversation = AiChatConversation::query()->firstOrFail();
        $this->assertSame($enrollment->id, $conversation->enrollment_id);
        $this->assertSame($section->id, $conversation->section_id);
        $this->assertSame(1, AiChatConversation::query()->count());
    }

    public function test_index_opens_latest_conversation_or_shows_empty_state(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $this->actingAs($student)
            ->get(route('ai-chat.index'))
            ->assertOk()
            ->assertSee('まだ相談履歴はありません');

        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $this->get(route('ai-chat.index'))
            ->assertRedirect(route('ai-chat.conversations.show', $conversation));
    }

    public function test_section_context_must_belong_to_a_published_enrolled_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $unrelatedCertification = Certification::factory()->published()->create();
        $section = $this->publishedSection($unrelatedCertification);

        $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.store'), ['section_id' => $section->id])
            ->assertNotFound();

        $this->assertDatabaseCount('ai_chat_conversations', 0);

        $part = Part::factory()->published()->forCertification($enrollment->certification)->create();
        $chapter = Chapter::factory()->published()->forPart($part)->create();
        $draftSection = Section::factory()->forChapter($chapter)->create();
        $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.store'), ['section_id' => $draftSection->id])
            ->assertNotFound();
    }

    public function test_conversation_details_are_owner_only_and_do_not_expose_operational_metadata(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $message = AiChatMessage::factory()
            ->for($conversation, 'conversation')
            ->fromAssistant()
            ->create([
                'content' => '回答内容',
                'model_name' => 'private-model-name',
                'input_tokens' => 100,
                'output_tokens' => 40,
                'response_time_ms' => 123,
            ]);

        $this->actingAs($student)
            ->getJson(route('ai-chat.conversations.show', $conversation))
            ->assertOk()
            ->assertJsonPath('messages.0.id', $message->id)
            ->assertJsonPath('messages.0.content', '回答内容')
            ->assertJsonMissingPath('messages.0.output_tokens')
            ->assertJsonMissingPath('messages.0.model_name');
        $this->get(route('ai-chat.conversations.show', $conversation))
            ->assertOk()
            ->assertSee('回答内容');

        $stranger = User::factory()->student()->inProgress()->create();
        $this->actingAs($stranger)
            ->getJson(route('ai-chat.conversations.show', $conversation))
            ->assertForbidden();
        $this->actingAs($stranger)
            ->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => '質問'])
            ->assertForbidden();
    }

    public function test_post_message_returns_expected_payload_and_generates_title(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);
        Http::fakeSequence()
            ->push([
                'modelVersion' => 'gemini-test',
                'candidates' => [['content' => ['parts' => [['text' => '2進数の説明です。']]]]],
                'usageMetadata' => ['promptTokenCount' => 18, 'candidatesTokenCount' => 9],
            ])
            ->push([
                'candidates' => [['content' => ['parts' => [['text' => '2進数の変換方法']]]]],
            ]);

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $response = $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.messages.store', $conversation), [
                'content' => '2進数の変換方法を教えてください。',
            ]);

        $response->assertOk()
            ->assertJsonPath('user_message.role', AiChatMessageRole::User->value)
            ->assertJsonPath('assistant_message.role', AiChatMessageRole::Assistant->value)
            ->assertJsonPath('assistant_message.status', AiChatMessageStatus::Completed->value)
            ->assertJsonPath('assistant_message.content', '2進数の説明です。')
            ->assertJsonPath('conversation.title', '2進数の変換方法')
            ->assertJsonMissingPath('assistant_message.output_tokens')
            ->assertJsonMissingPath('assistant_message.model_name');

        $this->assertDatabaseCount('ai_chat_messages', 2);
        $this->assertSame('2進数の変換方法', $conversation->fresh()->title);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), ':generateContent'));
    }

    public function test_gemini_failure_keeps_question_and_error_and_returns_502(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'unavailable'], 503),
        ]);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => '質問を保存'])
            ->assertStatus(502)
            ->assertJsonPath('upstream_status', 503);

        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User->value,
            'content' => '質問を保存',
        ]);
        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::Assistant->value,
            'status' => AiChatMessageStatus::Error->value,
        ]);
    }

    public function test_missing_api_key_is_reported_as_unavailable_while_preserving_the_question(): void
    {
        config(['services.gemini.api_key' => null]);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => 'キー未設定の質問'])
            ->assertStatus(502)
            ->assertJsonPath('reason', 'missing_api_key')
            ->assertJsonMissing(['message' => 'Gemini API key is not configured.']);

        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User->value,
            'content' => 'キー未設定の質問',
        ]);
    }

    public function test_full_screen_initial_message_is_saved_when_gemini_fails(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'unavailable'], 503),
        ]);
        $student = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($student)
            ->post(route('ai-chat.conversations.store'), [
                'source' => 'full-screen',
                'message' => '最初の質問を記録してください。',
            ]);

        $conversation = AiChatConversation::query()->where('user_id', $student->id)->firstOrFail();
        $response->assertRedirect(route('ai-chat.conversations.show', $conversation))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User->value,
            'content' => '最初の質問を記録してください。',
        ]);
    }

    public function test_daily_limit_and_validation_are_returned_as_json_errors(): void
    {
        config(['ai-chat.daily_limit' => 1]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '回答']]]]],
            ]),
        ]);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $this->actingAs($student);

        $this->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => '一問目'])
            ->assertOk();
        $this->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => '二問目'])
            ->assertStatus(429);
        $this->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');

        $this->assertSame(1, AiChatDailyUsage::query()->where('user_id', $student->id)->value('request_count'));
        $this->assertSame(2, AiChatMessage::query()->count());
    }

    public function test_owner_can_rename_toggle_auto_title_and_delete_conversation(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $this->actingAs($student);

        $this->patch(route('ai-chat.conversations.update', $conversation), [
            'title' => '手動タイトル',
            'auto_title_enabled' => '0',
        ])->assertRedirect(route('ai-chat.conversations.show', $conversation));
        $this->assertSame('手動タイトル', $conversation->fresh()->title);
        $this->assertFalse($conversation->fresh()->auto_title_enabled);

        $this->delete(route('ai-chat.conversations.destroy', $conversation))
            ->assertRedirect(route('ai-chat.index'));
        $this->assertSoftDeleted('ai_chat_conversations', ['id' => $conversation->id]);
    }

    public function test_disabled_auto_title_does_not_make_a_second_gemini_request(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '回答']]]]],
            ]),
        ]);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create([
            'user_id' => $student->id,
            'title' => '手動タイトル',
            'auto_title_enabled' => false,
        ]);

        $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => '質問'])
            ->assertOk()
            ->assertJsonPath('conversation.title', '手動タイトル');

        Http::assertSentCount(1);
    }

    private function publishedSection(Certification $certification): Section
    {
        $part = Part::factory()->published()->forCertification($certification)->create();
        $chapter = Chapter::factory()->published()->forPart($part)->create();

        return Section::factory()->published()->forChapter($chapter)->create();
    }
}
