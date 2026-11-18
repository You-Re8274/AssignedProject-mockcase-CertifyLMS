<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\GoogleCalendarOAuthService;
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('external-api')]
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

    public function test_auth_code_exchange_uses_google_token_endpoint_without_real_network(): void
    {
        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect' => 'https://lms.example.test/settings/google-calendar/callback',
        ]);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'exchanged-access-token',
                'expires_in' => 3600,
                'refresh_token' => 'exchanged-refresh-token',
                'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $client = new Client;
        $client->setHttpClient(new GuzzleClient(['handler' => $stack]));

        $tokens = (new GoogleCalendarOAuthService($client))->exchangeCode('oauth-code');

        $this->assertSame('exchanged-access-token', $tokens['access_token']);
        $this->assertSame('exchanged-refresh-token', $tokens['refresh_token']);
        $this->assertCount(1, $history);
        $this->assertSame('POST', $history[0]['request']->getMethod());
        $this->assertSame('https://oauth2.googleapis.com/token', (string) $history[0]['request']->getUri());
        parse_str((string) $history[0]['request']->getBody(), $parameters);
        $this->assertSame('authorization_code', $parameters['grant_type']);
        $this->assertSame('oauth-code', $parameters['code']);
        $this->assertSame('google-client-id', $parameters['client_id']);
    }
}
