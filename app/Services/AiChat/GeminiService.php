<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use App\Data\AiChat\GeminiResponse;
use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\GeminiApiException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class GeminiService
{
    public function generate(AiChatConversation $conversation, AiChatMessage $currentUserMessage): GeminiResponse
    {
        $apiKey = config('services.gemini.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new GeminiApiException(
                'Gemini API key is not configured.',
                reason: 'missing_api_key',
            );
        }

        $model = (string) config('ai-chat.gemini.model', 'gemini-2.5-flash');
        $url = rtrim((string) config('ai-chat.gemini.base_url'), '/')
            .'/models/'.rawurlencode($model).':generateContent';

        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->timeout((int) config('ai-chat.gemini.timeout', 30))
                ->post($url, $this->buildRequest($conversation, $currentUserMessage));
        } catch (ConnectionException) {
            throw new GeminiApiException(
                'Gemini API could not be reached.',
                reason: 'connection_error',
            );
        }

        if (! $response->successful()) {
            throw new GeminiApiException(
                'Gemini API returned an unsuccessful response.',
                upstreamStatus: $response->status(),
                reason: 'http_error',
            );
        }

        return $this->parseResponse($response, $model);
    }

    public function suggestTitle(string $userMessage, string $assistantResponse): string
    {
        $apiKey = config('services.gemini.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new GeminiApiException(
                'Gemini API key is not configured.',
                reason: 'missing_api_key',
            );
        }

        $model = (string) config('ai-chat.gemini.model', 'gemini-2.5-flash');
        $url = rtrim((string) config('ai-chat.gemini.base_url'), '/')
            .'/models/'.rawurlencode($model).':generateContent';

        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->timeout((int) config('ai-chat.gemini.timeout', 30))
                ->post($url, [
                    'systemInstruction' => [
                        'parts' => [[
                            'text' => '会話内容を表す短いタイトルを日本語で 1 つだけ作成してください。説明、引用符、句読点は付けず、30 文字以内にしてください。',
                        ]],
                    ],
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [[
                            'text' => "質問:\n{$userMessage}\n\n回答:\n{$assistantResponse}",
                        ]],
                    ]],
                ]);
        } catch (ConnectionException) {
            throw new GeminiApiException(
                'Gemini API could not be reached.',
                reason: 'connection_error',
            );
        }

        if (! $response->successful()) {
            throw new GeminiApiException(
                'Gemini API returned an unsuccessful response.',
                upstreamStatus: $response->status(),
                reason: 'http_error',
            );
        }

        $title = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($title) || trim($title) === '') {
            throw new GeminiApiException(
                'Gemini API returned an invalid title.',
                upstreamStatus: $response->status(),
                reason: 'invalid_response',
            );
        }

        $title = trim($title, " \t\n\r\0\x0B\"'「」『』");
        if ($title === '') {
            throw new GeminiApiException(
                'Gemini API returned an empty title.',
                upstreamStatus: $response->status(),
                reason: 'invalid_response',
            );
        }

        return mb_substr($title, 0, 100);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequest(AiChatConversation $conversation, AiChatMessage $currentUserMessage): array
    {
        $historyLimit = max(1, (int) config('ai-chat.history_messages', 12));
        $messages = AiChatMessage::query()
            ->where('ai_chat_conversation_id', $conversation->id)
            ->where('status', AiChatMessageStatus::Completed->value)
            ->whereIn('role', [AiChatMessageRole::User->value, AiChatMessageRole::Assistant->value])
            ->whereNotNull('content')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($historyLimit)
            ->get()
            ->reverse()
            ->values();

        $contents = $messages
            ->map(fn (AiChatMessage $message): array => [
                'role' => $message->role === AiChatMessageRole::Assistant ? 'model' : 'user',
                'parts' => [['text' => $message->content]],
            ])
            ->values()
            ->all();

        if ($messages->isEmpty() || $messages->last()?->isNot($currentUserMessage)) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => $currentUserMessage->content]],
            ];
        }

        $systemInstruction = $this->buildSystemInstruction($conversation);

        return [
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'contents' => $contents,
        ];
    }

    private function buildSystemInstruction(AiChatConversation $conversation): string
    {
        $instruction = 'あなたは資格学習を支援する学習アシスタントです。正確で分かりやすく説明し、不確かな内容は断定せず、必要に応じて教材や公式情報で確認するよう案内してください。';
        $enrollment = $conversation->enrollment;

        if ($enrollment !== null) {
            $certificationName = $enrollment->certification?->name;
            if ($certificationName !== null) {
                $instruction .= "\n受講中の資格: {$certificationName}";
            }
        }

        $section = $conversation->section;
        if ($section !== null) {
            $instruction .= "\n現在の教材 Section: {$section->title}\n教材本文:\n{$section->body}";
        }

        return $instruction;
    }

    private function parseResponse(Response $response, string $model): GeminiResponse
    {
        $payload = $response->json();
        $content = data_get($payload, 'candidates.0.content.parts.0.text');

        if (! is_array($payload) || ! is_string($content) || trim($content) === '') {
            throw new GeminiApiException(
                'Gemini API returned an invalid response.',
                upstreamStatus: $response->status(),
                reason: 'invalid_response',
            );
        }

        $usage = data_get($payload, 'usageMetadata', []);

        return new GeminiResponse(
            content: $content,
            modelName: (string) data_get($payload, 'modelVersion', $model),
            inputTokens: is_numeric(data_get($usage, 'promptTokenCount'))
                ? (int) data_get($usage, 'promptTokenCount')
                : null,
            outputTokens: is_numeric(data_get($usage, 'candidatesTokenCount'))
                ? (int) data_get($usage, 'candidatesTokenCount')
                : null,
        );
    }
}
