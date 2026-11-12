<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\GeminiApiException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use App\Services\AiChat\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_recent_history_and_qualification_context_and_parses_usage(): void
    {
        config([
            'services.gemini.api_key' => 'test-api-key',
            'ai-chat.gemini.model' => 'gemini-test',
        ]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'modelVersion' => 'gemini-test-001',
                'candidates' => [[
                    'content' => ['parts' => [['text' => '回答です。']]],
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 31,
                    'candidatesTokenCount' => 7,
                ],
            ]),
        ]);

        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $part = Part::factory()->published()->forCertification($enrollment->certification)->create();
        $chapter = Chapter::factory()->published()->forPart($part)->create();
        $section = Section::factory()->published()->forChapter($chapter)->create([
            'title' => '2進数の基礎',
            'body' => '教材本文に含まれる2進数の説明。',
        ]);
        $conversation = AiChatConversation::factory()
            ->withEnrollment($enrollment)
            ->forSection($section)
            ->create();
        $conversation->messages()->create([
            'role' => AiChatMessageRole::User,
            'status' => AiChatMessageStatus::Completed,
            'content' => '前の質問です。',
            'created_at' => now()->subMinutes(2),
        ]);
        $conversation->messages()->create([
            'role' => AiChatMessageRole::Assistant,
            'status' => AiChatMessageStatus::Completed,
            'content' => '前の回答です。',
            'created_at' => now()->subMinute(),
        ]);
        $currentMessage = $conversation->messages()->create([
            'role' => AiChatMessageRole::User,
            'status' => AiChatMessageStatus::Completed,
            'content' => '今回の質問です。',
        ]);

        $result = app(GeminiService::class)->generate($conversation, $currentMessage);

        $this->assertSame('回答です。', $result->content);
        $this->assertSame('gemini-test-001', $result->modelName);
        $this->assertSame(31, $result->inputTokens);
        $this->assertSame(7, $result->outputTokens);
        Http::assertSent(function (Request $request) use ($enrollment, $section): bool {
            $payload = $request->data();
            $systemInstruction = data_get($payload, 'systemInstruction.parts.0.text');
            $contents = $payload['contents'] ?? [];

            return $request->hasHeader('x-goog-api-key', 'test-api-key')
                && str_contains($request->url(), '/models/gemini-test:generateContent')
                && str_contains($systemInstruction, $enrollment->certification->name)
                && str_contains($systemInstruction, $section->body)
                && count($contents) === 3
                && data_get($contents, '0.role') === 'user'
                && data_get($contents, '1.role') === 'model'
                && data_get($contents, '2.parts.0.text') === '今回の質問です。';
        });
    }

    public function test_it_requires_an_api_key_before_making_a_request(): void
    {
        config(['services.gemini.api_key' => null]);
        Http::fake();
        $conversation = AiChatConversation::factory()->create();
        $message = AiChatMessage::factory()->for($conversation, 'conversation')->create();

        try {
            app(GeminiService::class)->generate($conversation, $message);
            $this->fail('A missing API key should fail explicitly.');
        } catch (GeminiApiException $exception) {
            $this->assertSame('missing_api_key', $exception->reason);
            $this->assertNull($exception->upstreamStatus);
        }

        Http::assertNothingSent();
    }

    public function test_it_exposes_upstream_status_without_returning_upstream_body(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'private upstream detail'], 503),
        ]);
        $conversation = AiChatConversation::factory()->create();
        $message = AiChatMessage::factory()->for($conversation, 'conversation')->create();

        try {
            app(GeminiService::class)->generate($conversation, $message);
            $this->fail('An upstream error should fail explicitly.');
        } catch (GeminiApiException $exception) {
            $this->assertSame(503, $exception->upstreamStatus);
            $this->assertStringNotContainsString('private upstream detail', $exception->getMessage());
        }
    }
}
