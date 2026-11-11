<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\FreeBusyRequest;
use InvalidArgumentException;
use RuntimeException;

class GoogleCalendarService
{
    public function __construct(
        private readonly GoogleCalendarOAuthService $oauth,
    ) {}

    /**
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function busyIntervals(User $coach, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($from->greaterThanOrEqualTo($to)) {
            throw new InvalidArgumentException('Busy interval start must be before its end.');
        }

        $client = $this->authenticatedClient($coach);
        if ($client === null) {
            return [];
        }

        $request = new FreeBusyRequest([
            'timeMin' => CarbonImmutable::instance($from)->toRfc3339String(),
            'timeMax' => CarbonImmutable::instance($to)->toRfc3339String(),
            'timeZone' => config('app.timezone'),
            'items' => [['id' => 'primary']],
        ]);
        $response = (new Calendar($client))->freebusy->query($request);
        $primaryCalendar = $response->getCalendars()['primary'] ?? null;
        if ($primaryCalendar === null) {
            throw new RuntimeException('Google Calendar did not return primary calendar availability.');
        }
        $busy = $primaryCalendar->getBusy() ?? [];

        return array_map(
            fn ($interval): array => [
                'start' => CarbonImmutable::parse($interval->getStart()),
                'end' => CarbonImmutable::parse($interval->getEnd()),
            ],
            $busy,
        );
    }

    public function createMeetingEvent(Meeting $meeting): ?string
    {
        if ($meeting->google_calendar_event_id) {
            $this->updateMeetingEvent($meeting);

            return $meeting->google_calendar_event_id;
        }

        $coach = $meeting->coach;
        if ($coach === null) {
            throw new RuntimeException('Meeting coach could not be loaded.');
        }

        $client = $this->authenticatedClient($coach);
        if ($client === null) {
            return null;
        }

        $event = (new Calendar($client))->events->insert(
            'primary',
            $this->makeEvent($meeting),
        );

        $eventId = $event->getId();
        if (! is_string($eventId) || $eventId === '') {
            throw new RuntimeException('Google Calendar did not return an event ID.');
        }

        $meeting->forceFill(['google_calendar_event_id' => $eventId])->save();

        return $eventId;
    }

    public function updateMeetingEvent(Meeting $meeting): void
    {
        if (! $meeting->google_calendar_event_id) {
            return;
        }

        $coach = $meeting->coach;
        if ($coach === null) {
            throw new RuntimeException('Meeting coach could not be loaded.');
        }

        $client = $this->authenticatedClient($coach);
        if ($client === null) {
            return;
        }

        (new Calendar($client))->events->update(
            'primary',
            $meeting->google_calendar_event_id,
            $this->makeEvent($meeting),
        );
    }

    public function deleteMeetingEvent(Meeting $meeting): void
    {
        if (! $meeting->google_calendar_event_id) {
            return;
        }

        $coach = $meeting->coach;
        if ($coach === null) {
            throw new RuntimeException('Meeting coach could not be loaded.');
        }

        $client = $this->authenticatedClient($coach);
        if ($client === null) {
            return;
        }

        (new Calendar($client))->events->delete('primary', $meeting->google_calendar_event_id);
        $meeting->forceFill(['google_calendar_event_id' => null])->save();
    }

    private function authenticatedClient(User $coach): ?Client
    {
        if (! filled($coach->google_access_token) && ! filled($coach->google_refresh_token)) {
            return null;
        }

        $client = $this->oauth->configuredClient();

        if (! filled($coach->google_access_token)
            || $coach->google_token_expires_at === null
            || $coach->google_token_expires_at->lessThanOrEqualTo(now())) {
            if (! filled($coach->google_refresh_token)) {
                throw new RuntimeException('Google Calendar access token is expired and cannot be refreshed.');
            }

            $tokens = $client->fetchAccessTokenWithRefreshToken($coach->google_refresh_token);
            if (isset($tokens['error']) || ! isset($tokens['access_token'], $tokens['expires_in'])
                || ! is_string($tokens['access_token'])
                || ! is_numeric($tokens['expires_in'])
                || (int) $tokens['expires_in'] < 1) {
                throw new RuntimeException('Google Calendar access token refresh failed.');
            }

            $refreshToken = $tokens['refresh_token'] ?? $coach->google_refresh_token;
            if (! is_string($refreshToken) || $refreshToken === '') {
                throw new RuntimeException('Google Calendar refresh response did not contain a valid refresh token.');
            }

            $coach->forceFill([
                'google_access_token' => $tokens['access_token'],
                'google_refresh_token' => $refreshToken,
                'google_token_expires_at' => now()->addSeconds((int) $tokens['expires_in']),
            ])->save();
        }

        $expiresIn = max(1, now()->diffInSeconds($coach->google_token_expires_at, false));
        $client->setAccessToken([
            'access_token' => $coach->google_access_token,
            'created' => now()->timestamp,
            'expires_in' => $expiresIn,
        ]);

        return $client;
    }

    private function makeEvent(Meeting $meeting): Event
    {
        $studentName = $meeting->student?->name ?? '受講生';
        $scheduledAt = CarbonImmutable::instance($meeting->scheduled_at);
        $endsAt = $scheduledAt->addHour();
        $description = $meeting->topic;

        if ($meeting->meeting_url_snapshot) {
            $description .= "\n\n面談 URL: ".$meeting->meeting_url_snapshot;
        }

        return new Event([
            'summary' => '面談: '.$studentName,
            'description' => $description,
            'start' => new EventDateTime([
                'dateTime' => $scheduledAt->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ]),
            'end' => new EventDateTime([
                'dateTime' => $endsAt->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ]),
        ]);
    }
}
