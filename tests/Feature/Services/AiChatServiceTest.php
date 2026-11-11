<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Exceptions\AiChat\GeminiApiException;
use App\Models\AiChatConversation;
use App\Models\AiChatDailyUsage;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\AiChat\AiChatService;
use App\Services\AiChat\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiChatServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_saves_a_completed_exchange_and_records_daily_usage(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'modelVersion' => 'gemini-test',
                'candidates' => [[
                    'content' => ['parts' => [['text' => '学習の回答です。']]],
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 25,
                    'candidatesTokenCount' => 10,
                ],
            ]),
        ]);
        $conversation = $this->conversation();

        $exchange = (new AiChatService(new GeminiService))->sendMessage($conversation, '質問です。');

        $this->assertSame(AiChatMessageRole::User, $exchange->userMessage->role);
        $this->assertSame(AiChatMessageStatus::Completed, $exchange->assistantMessage->status);
        $this->assertSame('学習の回答です。', $exchange->assistantMessage->content);
        $this->assertSame(25, $exchange->assistantMessage->input_tokens);
        $this->assertSame(10, $exchange->assistantMessage->output_tokens);
        $this->assertNotNull($exchange->assistantMessage->response_time_ms);
        $this->assertSame(1, AiChatDailyUsage::query()->where('user_id', $conversation->user_id)->value('request_count'));
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_it_keeps_the_user_message_and_error_record_when_gemini_fails(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'unavailable'], 503),
        ]);
        $conversation = $this->conversation();

        try {
            (new AiChatService(new GeminiService))->sendMessage($conversation, '質問を残してください。');
            $this->fail('The Gemini failure should be propagated after persistence.');
        } catch (GeminiApiException $exception) {
            $this->assertSame(503, $exception->upstreamStatus);
        }

        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User->value,
            'content' => '質問を残してください。',
        ]);
        $failedMessage = AiChatMessage::query()
            ->where('ai_chat_conversation_id', $conversation->id)
            ->where('role', AiChatMessageRole::Assistant->value)
            ->firstOrFail();
        $this->assertSame(AiChatMessageStatus::Error, $failedMessage->status);
        $this->assertSame('Gemini API request failed (HTTP 503).', $failedMessage->error_detail);
        $this->assertSame(1, AiChatDailyUsage::query()->where('user_id', $conversation->user_id)->value('request_count'));
    }

    public function test_it_counts_failed_attempts_toward_the_daily_limit(): void
    {
        config(['ai-chat.daily_limit' => 1]);
        config(['services.gemini.api_key' => null]);
        Http::fake();
        $conversation = $this->conversation();
        $service = new AiChatService(new GeminiService);

        try {
            $service->sendMessage($conversation, '失敗する質問です。');
        } catch (GeminiApiException) {
            // Failed requests still count toward the daily allowance.
        }

        try {
            $service->sendMessage($conversation, '上限後の質問です。');
            $this->fail('A request beyond the daily limit must be rejected.');
        } catch (AiChatDailyLimitExceededException) {
            $this->assertSame(2, $conversation->messages()->count());
        }

        $this->assertSame(1, AiChatDailyUsage::query()->where('user_id', $conversation->user_id)->value('request_count'));
    }

    public function test_title_generation_failure_does_not_fail_a_successful_answer(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);
        Http::fakeSequence()
            ->push([
                'candidates' => [['content' => ['parts' => [['text' => '回答内容']]]]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ])
            ->push(['error' => 'title generation unavailable'], 503);
        $conversation = $this->conversation();

        $exchange = (new AiChatService(new GeminiService))->sendMessage($conversation, '質問内容');

        $this->assertSame(AiChatMessageStatus::Completed, $exchange->assistantMessage->status);
        $this->assertSame('回答内容', $exchange->assistantMessage->content);
        $this->assertSame('新しい相談', $exchange->conversation->title);
        $this->assertNotNull($exchange->assistantMessage->response_time_ms);
    }

    private function conversation(): AiChatConversation
    {
        $student = User::factory()->student()->create();

        return AiChatConversation::factory()->create(['user_id' => $student->id]);
    }
}
