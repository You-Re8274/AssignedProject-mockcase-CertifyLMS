<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MeetingPackStatus;
use App\Http\Requests\MeetingPack\IndexRequest;
use App\Http\Requests\MeetingPack\StoreRequest;
use App\Http\Requests\MeetingPack\UpdateRequest;
use App\Models\MeetingPack;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MeetingPackController extends Controller
{
    public function index(IndexRequest $request): View
    {
        $validated = $request->validated();
        $plans = MeetingPack::query()
            ->when(
                $validated['keyword'] ?? null,
                fn ($query, string $keyword) => $query->where('name', 'like', '%'.$keyword.'%'),
            )
            ->when(
                $validated['status'] ?? null,
                fn ($query, string $status) => $query->where('status', $status),
            )
            ->ordered()
            ->paginate(15)
            ->withQueryString();

        return view('meeting-pack.management.index', [
            'plans' => $plans,
            'keyword' => $validated['keyword'] ?? '',
            'status' => $validated['status'] ?? '',
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', MeetingPack::class);

        return view('meeting-pack.management.create');
    }

    public function store(StoreRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $userId = $request->user()->id;

        $plan = MeetingPack::query()->create([
            ...$validated,
            'status' => MeetingPackStatus::Draft,
            'sort_order' => $validated['sort_order'] ?? 0,
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);

        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを作成しました。');
    }

    public function show(MeetingPack $plan): View
    {
        $this->authorize('view', $plan);

        return view('meeting-pack.management.show', [
            'plan' => $plan->load(['createdBy', 'updatedBy']),
        ]);
    }

    public function edit(MeetingPack $plan): View
    {
        $this->authorize('update', $plan);

        return view('meeting-pack.management.edit', ['plan' => $plan]);
    }

    public function update(UpdateRequest $request, MeetingPack $plan): RedirectResponse
    {
        $plan->update([
            ...$request->validated(),
            'sort_order' => $request->validated('sort_order', 0),
            'updated_by_user_id' => $request->user()->id,
        ]);

        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを更新しました。');
    }

    public function destroy(MeetingPack $plan): RedirectResponse
    {
        $this->authorize('delete', $plan);
        $plan->delete();

        return redirect()
            ->route('admin.meeting-packs.index')
            ->with('success', '面談パックを削除しました。');
    }

    public function publish(MeetingPack $plan): RedirectResponse
    {
        $this->authorize('publish', $plan);
        $plan->update([
            'status' => MeetingPackStatus::Published,
            'updated_by_user_id' => request()->user()->id,
        ]);

        return $this->redirectToShow($plan, '面談パックを公開しました。');
    }

    public function archive(MeetingPack $plan): RedirectResponse
    {
        $this->authorize('archive', $plan);
        $plan->update([
            'status' => MeetingPackStatus::Archived,
            'updated_by_user_id' => request()->user()->id,
        ]);

        return $this->redirectToShow($plan, '面談パックをアーカイブしました。');
    }

    public function unarchive(MeetingPack $plan): RedirectResponse
    {
        $this->authorize('unarchive', $plan);
        $plan->update([
            'status' => MeetingPackStatus::Draft,
            'updated_by_user_id' => request()->user()->id,
        ]);

        return $this->redirectToShow($plan, '面談パックを下書きに戻しました。');
    }

    private function redirectToShow(MeetingPack $plan, string $message): RedirectResponse
    {
        return redirect()
            ->route('admin.meeting-packs.show', $plan)
            ->with('success', $message);
    }
}
