<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

final class NotificationActionUrlResolver
{
    public function resolve(Request $request, DatabaseNotification $notification): string
    {
        $fallback = route('notifications.index');
        $actionUrl = $notification->data['action_url'] ?? null;
        if (! is_string($actionUrl) || $actionUrl === '') {
            return $fallback;
        }

        $parts = parse_url($actionUrl);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return $fallback;
        }

        if (isset($parts['host'])) {
            if (strcasecmp($parts['host'], $request->getHost()) !== 0) {
                return $fallback;
            }
        } elseif (isset($parts['scheme'])) {
            return $fallback;
        }

        $path = $parts['path'] ?? '';
        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return $fallback;
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
