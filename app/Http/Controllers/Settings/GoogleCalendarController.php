<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleCalendarOAuthService;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GoogleCalendarController extends Controller
{
    public function redirect(Request $request, GoogleCalendarOAuthService $oauth): RedirectResponse
    {
        if (! $oauth->isConfigured()) {
            return redirect()
                ->route('settings.availability.index')
                ->with('error', 'Googleカレンダー連携の設定が完了していません。');
        }

        $state = Str::random(64);
        $request->session()->put([
            'google_calendar.oauth_state' => hash('sha256', $state),
            'google_calendar.redirect_path' => $this->safeRedirectPath(
                (string) $request->query('redirect_path', '/settings/availability'),
            ),
        ]);

        return redirect()->away($oauth->authorizationUrl($state));
    }

    public function callback(Request $request, GoogleCalendarOAuthService $oauth): RedirectResponse
    {
        $expectedState = $request->session()->pull('google_calendar.oauth_state');
        $redirectPath = $request->session()->pull('google_calendar.redirect_path', '/settings/availability');
        $state = $request->query('state');

        if (! is_string($expectedState) || ! is_string($state) || ! hash_equals($expectedState, hash('sha256', $state))) {
            return redirect($this->safeRedirectPath((string) $redirectPath))
                ->with('error', 'Googleカレンダー連携を確認できませんでした。もう一度お試しください。');
        }

        if ($request->filled('error')) {
            return redirect($this->safeRedirectPath((string) $redirectPath))
                ->with('error', 'Googleカレンダー連携がキャンセルされました。');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return redirect($this->safeRedirectPath((string) $redirectPath))
                ->with('error', 'Googleから認証コードを受け取れませんでした。');
        }

        if (! $oauth->isConfigured()) {
            return redirect($this->safeRedirectPath((string) $redirectPath))
                ->with('error', 'Googleカレンダー連携の設定が完了していません。');
        }

        try {
            $tokens = $oauth->exchangeCode($code);
        } catch (GuzzleException) {
            return redirect($this->safeRedirectPath((string) $redirectPath))
                ->with('error', 'Googleとの通信に失敗しました。時間をおいてもう一度お試しください。');
        }

        if (! isset($tokens['access_token'], $tokens['expires_in'])
            || ! is_string($tokens['access_token'])
            || $tokens['access_token'] === ''
            || ! is_numeric($tokens['expires_in'])
            || (int) $tokens['expires_in'] < 1) {
            return redirect($this->safeRedirectPath((string) $redirectPath))
                ->with('error', 'Googleから有効なアクセストークンを受け取れませんでした。');
        }

        /** @var User $user */
        $user = $request->user();
        $user->forceFill([
            'google_access_token' => $tokens['access_token'],
            'google_token_expires_at' => now()->addSeconds((int) $tokens['expires_in']),
        ]);

        if (isset($tokens['refresh_token']) && is_string($tokens['refresh_token']) && $tokens['refresh_token'] !== '') {
            $user->google_refresh_token = $tokens['refresh_token'];
        }

        $user->save();

        return redirect($this->safeRedirectPath((string) $redirectPath))
            ->with('success', 'Googleカレンダーと連携しました。');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->forceFill([
            'google_access_token' => null,
            'google_refresh_token' => null,
            'google_token_expires_at' => null,
        ])->save();

        return redirect()
            ->route('settings.availability.index')
            ->with('success', 'Googleカレンダー連携を解除しました。');
    }

    private function safeRedirectPath(string $path): string
    {
        $parsed = parse_url($path);

        if (str_starts_with($path, '/')
            && ! str_starts_with($path, '//')
            && ! str_contains($path, '\\')
            && is_array($parsed)
            && ! isset($parsed['host'])
            && ! isset($parsed['scheme'])) {
            return $path;
        }

        return '/settings/availability';
    }
}
