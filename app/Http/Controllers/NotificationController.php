<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\NotificationActionUrlResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $tab = $request->query('tab', 'all');
        abort_unless(in_array($tab, ['all', 'unread'], true), 404);

        $notifications = $request->user()
            ->notifications()
            ->when($tab === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('notifications.index', [
            'notifications' => $notifications,
            'unreadCount' => $request->user()->unreadNotifications()->count(),
            'tab' => $tab,
        ]);
    }

    public function markAsRead(
        Request $request,
        string $notification,
        NotificationActionUrlResolver $actionUrlResolver,
    ): RedirectResponse {
        $record = $request->user()->notifications()->findOrFail($notification);
        $this->authorize('markAsRead', $record);

        if ($record->read_at === null) {
            $record->markAsRead();
        }

        if (($record->data['notification_type'] ?? null) === 'admin_announcement') {
            return redirect()->route('notifications.show', $record);
        }

        return redirect()->to($actionUrlResolver->resolve($request, $record));
    }

    public function show(Request $request, string $notification): View
    {
        $record = $request->user()->notifications()->findOrFail($notification);
        $this->authorize('view', $record);

        if ($record->read_at === null) {
            $record->markAsRead();
        }

        return view('notifications.show', [
            'notification' => $record,
        ]);
    }

    public function markAllAsRead(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $request->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        return redirect()
            ->route('notifications.index')
            ->with('success', 'すべての通知を既読にしました。');
    }
}
