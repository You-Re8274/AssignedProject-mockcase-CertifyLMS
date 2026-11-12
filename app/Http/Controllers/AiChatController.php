<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AiChatMessageStatus;
use App\Enums\EnrollmentStatus;
use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Exceptions\AiChat\GeminiApiException;
use App\Http\Requests\AiChat\StoreConversationRequest;
use App\Http\Requests\AiChat\StoreMessageRequest;
use App\Http\Requests\AiChat\UpdateConversationRequest;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Enrollment;
use App\Models\Section;
use App\Services\AiChat\AiChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AiChatController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $this->authorize('viewAny', AiChatConversation::class);

        $latest = $request->user()->aiChatConversations()
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->first();

        if ($latest !== null) {
            return redirect()->route('ai-chat.conversations.show', $latest);
        }

        return view('ai-chat.empty-state');
    }

    public function store(StoreConversationRequest $request, AiChatService $service): JsonResponse|RedirectResponse
    {
        $attributes = $request->validated();
        $section = $this->sectionContext($attributes['section_id'] ?? null, $request);
        $enrollment = $this->enrollmentContext($request, $section);

        [$conversation, $created] = DB::transaction(function () use ($request, $section, $enrollment): array {
            $user = $request->user()->newQuery()->lockForUpdate()->findOrFail($request->user()->id);
            $conversation = null;
            if ($section !== null) {
                $conversation = $user->aiChatConversations()
                    ->where('section_id', $section->id)
                    ->where('enrollment_id', $enrollment?->id)
                    ->orderByDesc('last_message_at')
                    ->first();
            }

            if ($conversation !== null) {
                return [$conversation, false];
            }

            return [$user->aiChatConversations()->create([
                'enrollment_id' => $enrollment?->id,
                'section_id' => $section?->id,
                'title' => '新しい相談',
                'auto_title_enabled' => true,
            ]), true];
        });

        if ($request->expectsJson()) {
            return response()->json([
                'conversation' => $this->conversationPayload($conversation),
            ], $created ? Response::HTTP_CREATED : Response::HTTP_OK);
        }

        $message = trim((string) ($attributes['message'] ?? ''));
        if ($message !== '') {
            try {
                $service->sendMessage($conversation, $message);
            } catch (AiChatDailyLimitExceededException) {
                return redirect()
                    ->route('ai-chat.conversations.show', $conversation)
                    ->with('error', '本日の利用上限に達しました。明日 0:00 以降に再度ご利用ください。');
            } catch (GeminiApiException $exception) {
                return redirect()
                    ->route('ai-chat.conversations.show', $conversation)
                    ->with(
                        'error',
                        $exception->reason === 'missing_api_key'
                            ? 'AI 相談は現在利用できません。管理者にお問い合わせください。'
                            : '質問は保存されましたが、AI が応答できませんでした。画面から再送できます。',
                    );
            }
        }

        return redirect()->route('ai-chat.conversations.show', $conversation);
    }

    public function show(Request $request, AiChatConversation $conversation): View|JsonResponse
    {
        $this->authorize('view', $conversation);

        $conversation->load([
            'enrollment.certification',
            'section',
            'messages' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'conversation' => $this->conversationPayload($conversation),
                'messages' => $conversation->messages->map(fn ($message): array => [
                    'id' => $message->id,
                    'role' => $message->role->value,
                    'status' => $message->status->value,
                    'content' => $message->status === AiChatMessageStatus::Error
                        ? 'AI が応答できませんでした。しばらく時間をおいて再試行してください。'
                        : $message->content,
                    'created_at' => $message->created_at?->toISOString(),
                    'updated_at' => $message->updated_at?->toISOString(),
                ])->values(),
            ]);
        }

        return view('ai-chat.show', ['conversation' => $conversation]);
    }

    public function update(UpdateConversationRequest $request, AiChatConversation $conversation): RedirectResponse|JsonResponse
    {
        $conversation->fill([
            'title' => $request->validated('title'),
            'auto_title_enabled' => $request->validated('auto_title_enabled', $conversation->auto_title_enabled),
        ])->save();

        if ($request->expectsJson()) {
            return response()->json(['conversation' => $this->conversationPayload($conversation->refresh())]);
        }

        return redirect()
            ->route('ai-chat.conversations.show', $conversation)
            ->with('success', '会話を更新しました。');
    }

    public function destroy(Request $request, AiChatConversation $conversation): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $conversation);
        $conversation->delete();

        if ($request->expectsJson()) {
            return response()->json(null, Response::HTTP_NO_CONTENT);
        }

        return redirect()->route('ai-chat.index')->with('success', '会話を削除しました。');
    }

    public function storeMessage(
        StoreMessageRequest $request,
        AiChatConversation $conversation,
        AiChatService $service,
    ): JsonResponse {
        try {
            $exchange = $service->sendMessage($conversation, $request->validated('content'));
        } catch (AiChatDailyLimitExceededException) {
            return response()->json([
                'message' => '本日の利用上限に達しました。',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (GeminiApiException $exception) {
            return response()->json([
                'message' => 'AI が応答できませんでした。保存された質問を再送できます。',
                'reason' => $exception->reason,
                'upstream_status' => $exception->upstreamStatus,
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'user_message' => $this->messagePayload($exchange->userMessage),
            'assistant_message' => $this->messagePayload($exchange->assistantMessage),
            'conversation' => $this->conversationPayload($exchange->conversation),
        ]);
    }

    private function sectionContext(?string $sectionId, StoreConversationRequest $request): ?Section
    {
        if ($sectionId === null) {
            return null;
        }

        $section = Section::query()
            ->published()
            ->with('chapter.part')
            ->whereKey($sectionId)
            ->firstOrFail();

        $enrolled = $request->user()->enrollments()
            ->where('status', EnrollmentStatus::Learning->value)
            ->where('certification_id', $section->chapter->part->certification_id)
            ->exists();
        abort_unless($enrolled, 404);

        return $section;
    }

    private function enrollmentContext(Request $request, ?Section $section): ?Enrollment
    {
        $enrollments = $request->user()->enrollments()
            ->where('status', EnrollmentStatus::Learning->value)
            ->with('certification');

        if ($section !== null) {
            return $enrollments
                ->where('certification_id', $section->chapter->part->certification_id)
                ->firstOrFail();
        }

        $defaultEnrollmentId = $request->user()->default_enrollment_id;

        return $defaultEnrollmentId !== null
            ? $enrollments->whereKey($defaultEnrollmentId)->first()
                ?? $request->user()->enrollments()->where('status', EnrollmentStatus::Learning->value)->with('certification')->first()
            : $enrollments->first();
    }

    /**
     * @return array{id: string, title: string, last_message_at: ?string}
     */
    private function conversationPayload(AiChatConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(AiChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role->value,
            'status' => $message->status->value,
            'content' => $message->content,
            'created_at' => $message->created_at?->toISOString(),
        ];
    }
}
