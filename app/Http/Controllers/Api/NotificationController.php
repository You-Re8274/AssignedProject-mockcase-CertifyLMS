<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationActionUrlResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    private const POPOVER_LIMIT = 10;

    public function index(Request $request, NotificationActionUrlResolver $actionUrlResolver): JsonResponse
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $user = $request->user();
        $notifications = $user->notifications()
            ->latest()
            ->limit(self::POPOVER_LIMIT)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => $this->serializeNotification(
                $request,
                $notification,
                $actionUrlResolver,
            ))
            ->values();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markAsRead(
        Request $request,
        string $notification,
        NotificationActionUrlResolver $actionUrlResolver,
    ): JsonResponse {
        $record = $request->user()->notifications()->findOrFail($notification);
        $this->authorize('markAsRead', $record);

        if ($record->read_at === null) {
            $record->markAsRead();
            $record->refresh();
        }

        return response()->json([
            'notification' => $this->serializeNotification($request, $record, $actionUrlResolver),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $updatedCount = $request->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        return response()->json([
            'updated_count' => $updatedCount,
            'unread_count' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeNotification(
        Request $request,
        DatabaseNotification $notification,
        NotificationActionUrlResolver $actionUrlResolver,
    ): array {
        return [
            'id' => $notification->id,
            'title' => is_string($notification->data['title'] ?? null)
                ? $notification->data['title']
                : '',
            'message' => is_string($notification->data['message'] ?? null)
                ? $notification->data['message']
                : (is_string($notification->data['body'] ?? null) ? $notification->data['body'] : ''),
            'action_url' => $actionUrlResolver->resolve($request, $notification),
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
