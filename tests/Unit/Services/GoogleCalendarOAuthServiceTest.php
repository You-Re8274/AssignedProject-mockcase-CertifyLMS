<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\GoogleCalendarOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleCalendarOAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorization_url_requests_offline_calendar_access_and_preserves_state(): void
    {
        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect' => 'https://lms.example.test/settings/google-calendar/callback',
        ]);

        $url = app(GoogleCalendarOAuthService::class)->authorizationUrl('csrf-state-token');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('google-client-id', $query['client_id']);
        $this->assertSame('csrf-state-token', $query['state']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertContains('https://www.googleapis.com/auth/calendar.events', explode(' ', $query['scope']));
        $this->assertContains('https://www.googleapis.com/auth/calendar.freebusy', explode(' ', $query['scope']));
    }
}
