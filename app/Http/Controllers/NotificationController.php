<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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

    public function markAsRead(Request $request, string $notification): RedirectResponse
    {
        $record = $request->user()->notifications()->findOrFail($notification);
        $this->authorize('markAsRead', $record);

        if ($record->read_at === null) {
            $record->markAsRead();
        }

        return redirect()->to($this->safeActionUrl($request, $record));
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

    private function safeActionUrl(Request $request, DatabaseNotification $notification): string
    {
        $actionUrl = $notification->data['action_url'] ?? null;
        if (! is_string($actionUrl) || $actionUrl === '') {
            return route('notifications.index');
        }

        $parts = parse_url($actionUrl);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return route('notifications.index');
        }

        if (isset($parts['host'])) {
            if (strcasecmp($parts['host'], $request->getHost()) !== 0) {
                return route('notifications.index');
            }
        } elseif (isset($parts['scheme'])) {
            return route('notifications.index');
        }

        $path = $parts['path'] ?? '';
        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return route('notifications.index');
        }

        $url = $request->getSchemeAndHttpHost().$path;
        if (isset($parts['query'])) {
            $url .= '?'.$parts['query'];
        }
        if (isset($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }
}
