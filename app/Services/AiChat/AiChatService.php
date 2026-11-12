<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use App\Data\AiChat\AiChatExchange;
use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Exceptions\AiChat\GeminiApiException;
use App\Models\AiChatConversation;
use App\Models\AiChatDailyUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class AiChatService
{
    public function __construct(
        private readonly GeminiService $gemini,
    ) {}

    public function sendMessage(AiChatConversation $conversation, string $content): AiChatExchange
    {
        [$userMessage, $assistantMessage] = DB::transaction(function () use ($conversation, $content): array {
            $usageDate = now()->toDateString();
            $now = now();

            DB::table('ai_chat_daily_usages')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'user_id' => $conversation->user_id,
                'usage_date' => $usageDate,
                'request_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $usage = AiChatDailyUsage::query()
                ->where('user_id', $conversation->user_id)
                ->where('usage_date', $usageDate)
                ->lockForUpdate()
                ->firstOrFail();

            $dailyLimit = max(1, (int) config('ai-chat.daily_limit', 30));
            if ($usage->request_count >= $dailyLimit) {
                throw new AiChatDailyLimitExceededException;
            }

            $usage->increment('request_count');

            $userMessage = $conversation->messages()->create([
                'role' => AiChatMessageRole::User,
                'status' => AiChatMessageStatus::Completed,
                'content' => $content,
            ]);
            $assistantMessage = $conversation->messages()->create([
                'role' => AiChatMessageRole::Assistant,
                'status' => AiChatMessageStatus::Pending,
                'content' => '',
            ]);

            $conversation->forceFill(['last_message_at' => $now])->save();

            return [$userMessage, $assistantMessage];
        });

        $startedAt = hrtime(true);

        try {
            $response = $this->gemini->generate($conversation, $userMessage);
            $assistantMessage->forceFill([
                'status' => AiChatMessageStatus::Completed,
                'content' => $response->content,
                'model_name' => $response->modelName,
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'error_detail' => null,
            ])->save();

            $conversation = $conversation->refresh();
            if ($conversation->auto_title_enabled) {
                try {
                    $title = $this->gemini->suggestTitle($userMessage->content, $response->content);
                    $conversation->forceFill(['title' => $title])->save();
                } catch (GeminiApiException $exception) {
                    Log::channel('ai-chat')->warning('AI chat title generation failed.', [
                        'conversation_id' => $conversation->id,
                        'reason' => $exception->reason,
                        'upstream_status' => $exception->upstreamStatus,
                    ]);
                }
            }

            $assistantMessage->forceFill([
                'response_time_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            ])->save();

            return new AiChatExchange(
                userMessage: $userMessage->refresh(),
                assistantMessage: $assistantMessage->refresh(),
                conversation: $conversation->refresh(),
            );
        } catch (GeminiApiException $exception) {
            $assistantMessage->forceFill([
                'status' => AiChatMessageStatus::Error,
                'content' => '',
                'model_name' => (string) config('ai-chat.gemini.model', 'gemini-2.5-flash'),
                'response_time_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                'error_detail' => $this->errorDetail($exception),
            ])->save();

            Log::channel('ai-chat')->warning('AI chat generation failed.', [
                'conversation_id' => $conversation->id,
                'message_id' => $assistantMessage->id,
                'reason' => $exception->reason,
                'upstream_status' => $exception->upstreamStatus,
            ]);

            throw $exception;
        }
    }

    private function errorDetail(GeminiApiException $exception): string
    {
        if ($exception->upstreamStatus !== null) {
            return "Gemini API request failed (HTTP {$exception->upstreamStatus}).";
        }

        return match ($exception->reason) {
            'missing_api_key' => 'Gemini API key is not configured.',
            'connection_error' => 'Gemini API connection failed.',
            default => 'Gemini API request failed.',
        };
    }
}
