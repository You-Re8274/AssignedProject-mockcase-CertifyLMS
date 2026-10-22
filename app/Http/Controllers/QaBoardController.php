<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;
use App\Http\Requests\QaBoard\IndexRequest;
use App\Http\Requests\QaBoard\StoreReplyRequest;
use App\Http\Requests\QaBoard\StoreThreadRequest;
use App\Http\Requests\QaBoard\UpdateReplyRequest;
use App\Http\Requests\QaBoard\UpdateThreadRequest;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\UseCases\QaBoard\DestroyReplyAction;
use App\UseCases\QaBoard\DestroyThreadAction;
use App\UseCases\QaBoard\IndexAction;
use App\UseCases\QaBoard\SetThreadResolvedAction;
use App\UseCases\QaBoard\ShowAction;
use App\UseCases\QaBoard\StoreReplyAction;
use App\UseCases\QaBoard\StoreThreadAction;
use App\UseCases\QaBoard\UpdateReplyAction;
use App\UseCases\QaBoard\UpdateThreadAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class QaBoardController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        $result = $action($request->user(), $request->validated());

        return view('qa-thread.index', [
            'threads' => $result['threads'],
            'certifications' => $result['certifications'],
            'filters' => [
                'certification_id' => $request->validated('certification_id', ''),
                'status' => $request->validated('status', ''),
                'keyword' => $request->validated('keyword', ''),
            ],
            'publishedStatus' => CertificationStatus::Published,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', QaThread::class);

        return view('qa-thread.create', [
            'certifications' => Certification::query()->published()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreThreadRequest $request, StoreThreadAction $action): RedirectResponse
    {
        $thread = $action($request->user(), $request->validated());

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を投稿しました。');
    }

    public function show(QaThread $thread, ShowAction $action): View
    {
        $this->authorize('view', $thread);

        return view('qa-thread.show', [
            'thread' => $action($thread),
        ]);
    }

    public function edit(QaThread $thread): View
    {
        $this->authorize('update', $thread);

        return view('qa-thread.edit', ['thread' => $thread->load('certification')]);
    }

    public function update(
        UpdateThreadRequest $request,
        QaThread $thread,
        UpdateThreadAction $action,
    ): RedirectResponse {
        $action($thread, $request->validated());

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を更新しました。');
    }

    public function destroy(QaThread $thread, DestroyThreadAction $action): RedirectResponse
    {
        $this->authorize('delete', $thread);
        $action($thread);

        return redirect()
            ->route('qa-board.index')
            ->with('success', '質問を削除しました。');
    }

    public function resolve(QaThread $thread, SetThreadResolvedAction $action): RedirectResponse
    {
        $this->authorize('resolve', $thread);
        $action($thread, true);

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を解決済にしました。');
    }

    public function unresolve(QaThread $thread, SetThreadResolvedAction $action): RedirectResponse
    {
        $this->authorize('unresolve', $thread);
        $action($thread, false);

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を未解決に戻しました。');
    }

    public function storeReply(
        StoreReplyRequest $request,
        QaThread $thread,
        StoreReplyAction $action,
    ): RedirectResponse {
        $action($request->user(), $thread, $request->validated());

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '回答を投稿しました。');
    }

    public function editReply(QaThread $thread, QaReply $reply): View
    {
        abort_unless($reply->qa_thread_id === $thread->id, 404);
        $this->authorize('update', $reply);

        return view('qa-thread.reply-edit', [
            'thread' => $thread,
            'reply' => $reply,
        ]);
    }

    public function updateReply(
        UpdateReplyRequest $request,
        QaThread $thread,
        QaReply $reply,
        UpdateReplyAction $action,
    ): RedirectResponse {
        $action($reply, $request->validated());

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '回答を更新しました。');
    }

    public function destroyReply(
        QaThread $thread,
        QaReply $reply,
        DestroyReplyAction $action,
    ): RedirectResponse {
        abort_unless($reply->qa_thread_id === $thread->id, 404);
        $this->authorize('delete', $reply);
        $action($reply);

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '回答を削除しました。');
    }
}
