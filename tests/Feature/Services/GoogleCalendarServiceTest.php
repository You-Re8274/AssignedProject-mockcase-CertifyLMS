<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarOAuthService;
use App\Services\GoogleCalendarService;
use Carbon\CarbonImmutable;
use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

class GoogleCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_refreshes_expired_token_and_creates_event_with_lms_event_id(): void
    {
        $coach = User::factory()->coach()->create([
            'google_access_token' => 'expired-access-token',
            'google_refresh_token' => 'refresh-token',
            'google_token_expires_at' => now()->subMinute(),
        ]);
        $meeting = Meeting::factory()->forCoach($coach)->create([
            'scheduled_at' => CarbonImmutable::parse('2026-11-15 10:00:00'),
            'topic' => '学習計画を相談',
            'meeting_url_snapshot' => 'https://meet.example.test/room',
        ]);
        $history = [];
        $service = $this->serviceWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'new-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'google-event-123',
                'status' => 'confirmed',
            ])),
        ], $history);

        $eventId = $service->createMeetingEvent($meeting);

        $this->assertSame('google-event-123', $eventId);
        $this->assertSame('google-event-123', $meeting->fresh()->google_calendar_event_id);
        $this->assertSame('new-access-token', $coach->fresh()->google_access_token);
        $this->assertTrue($coach->fresh()->google_token_expires_at->isFuture());
        $this->assertCount(2, $history);
        $this->assertStringContainsString('oauth2.googleapis.com/token', (string) $history[0]['request']->getUri());
        $this->assertStringContainsString('/calendar/v3/calendars/primary/events', (string) $history[1]['request']->getUri());

        $eventPayload = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame('面談: '.$meeting->student->name, $eventPayload['summary']);
        $this->assertSame('学習計画を相談'."\n\n面談 URL: https://meet.example.test/room", $eventPayload['description']);
        $this->assertSame('2026-11-15T10:00:00+09:00', $eventPayload['start']['dateTime']);
        $this->assertSame('2026-11-15T11:00:00+09:00', $eventPayload['end']['dateTime']);
    }

    public function test_queries_primary_calendar_busy_intervals(): void
    {
        $coach = User::factory()->coach()->create([
            'google_access_token' => 'access-token',
            'google_token_expires_at' => now()->addHour(),
        ]);
        $history = [];
        $service = $this->serviceWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'kind' => 'calendar#freeBusy',
                'calendars' => [
                    'primary' => [
                        'busy' => [[
                            'start' => '2026-11-15T10:00:00+09:00',
                            'end' => '2026-11-15T11:00:00+09:00',
                        ]],
                    ],
                ],
            ])),
        ], $history);

        $busy = $service->busyIntervals(
            $coach,
            CarbonImmutable::parse('2026-11-15 09:00:00'),
            CarbonImmutable::parse('2026-11-15 12:00:00'),
        );

        $this->assertCount(1, $busy);
        $this->assertSame('2026-11-15T10:00:00+09:00', $busy[0]['start']->toRfc3339String());
        $this->assertSame('2026-11-15T11:00:00+09:00', $busy[0]['end']->toRfc3339String());
        $this->assertStringContainsString('/freeBusy', (string) $history[0]['request']->getUri());
    }

    public function test_updates_and_deletes_existing_calendar_event(): void
    {
        $coach = User::factory()->coach()->create([
            'google_access_token' => 'access-token',
            'google_token_expires_at' => now()->addHour(),
        ]);
        $meeting = Meeting::factory()->forCoach($coach)->create([
            'google_calendar_event_id' => 'event-to-delete',
        ]);
        $history = [];
        $service = $this->serviceWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'event-to-delete',
                'status' => 'confirmed',
            ])),
            new Response(204),
        ], $history);

        $this->assertSame('event-to-delete', $service->createMeetingEvent($meeting));
        $service->deleteMeetingEvent($meeting);

        $this->assertCount(2, $history);
        $this->assertSame('PUT', $history[0]['request']->getMethod());
        $this->assertSame('DELETE', $history[1]['request']->getMethod());
        $this->assertStringContainsString('/events/event-to-delete', (string) $history[0]['request']->getUri());
        $this->assertNull($meeting->fresh()->google_calendar_event_id);
    }

    public function test_unconnected_coach_does_not_make_calendar_requests(): void
    {
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->forCoach($coach)->create();
        $history = [];
        $service = $this->serviceWithResponses([], $history);

        $this->assertSame([], $service->busyIntervals(
            $coach,
            now()->startOfHour(),
            now()->addHour()->startOfHour(),
        ));
        $this->assertNull($service->createMeetingEvent($meeting));
        $this->assertSame([], $history);
    }

    public function test_expired_access_token_without_refresh_token_fails_explicitly(): void
    {
        $coach = User::factory()->coach()->create([
            'google_access_token' => 'expired-access-token',
            'google_token_expires_at' => now()->subMinute(),
        ]);
        $history = [];
        $service = $this->serviceWithResponses([], $history);

        $this->expectException(RuntimeException::class);
        $service->busyIntervals($coach, now(), now()->addHour());
    }

    public function test_failed_event_deletion_keeps_event_id_for_a_later_retry(): void
    {
        $coach = User::factory()->coach()->create([
            'google_access_token' => 'access-token',
            'google_token_expires_at' => now()->addHour(),
        ]);
        $meeting = Meeting::factory()->forCoach($coach)->create([
            'google_calendar_event_id' => 'event-to-retry',
        ]);
        $history = [];
        $service = $this->serviceWithResponses([
            new Response(404, ['Content-Type' => 'application/json'], json_encode([
                'error' => [
                    'code' => 404,
                    'message' => 'Event not found',
                ],
            ])),
        ], $history);

        try {
            $service->deleteMeetingEvent($meeting);
            $this->fail('Expected Google Calendar API failure.');
        } catch (GoogleServiceException) {
            $this->assertSame('event-to-retry', $meeting->fresh()->google_calendar_event_id);
        }
    }

    public function test_invalid_busy_interval_is_rejected_before_api_call(): void
    {
        $coach = User::factory()->coach()->create([
            'google_access_token' => 'access-token',
            'google_token_expires_at' => now()->addHour(),
        ]);
        $history = [];
        $service = $this->serviceWithResponses([], $history);
        $sameInstant = now();

        $this->expectException(\InvalidArgumentException::class);
        $service->busyIntervals($coach, $sameInstant, $sameInstant->copy());
    }

    /**
     * @param array<int, Response> $responses
     * @param array<int, array{request: RequestInterface}> $history
     */
    private function serviceWithResponses(array $responses, array &$history): GoogleCalendarService
    {
        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect' => 'https://lms.example.test/settings/google-calendar/callback',
            'app.timezone' => 'Asia/Tokyo',
        ]);

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $client = new Client;
        $client->setHttpClient(new HttpClient(['handler' => $stack]));

        return new GoogleCalendarService(new GoogleCalendarOAuthService($client));
    }
}
