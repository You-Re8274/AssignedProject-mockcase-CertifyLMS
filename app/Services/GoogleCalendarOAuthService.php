<?php

declare(strict_types=1);

namespace App\Services;

use Google\Client;
use Google\Service\Calendar;

class GoogleCalendarOAuthService
{
    public function __construct(
        private readonly Client $client,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    public function authorizationUrl(string $state): string
    {
        $client = $this->configuredClient();
        $client->setState($state);

        return $client->createAuthUrl([
            Calendar::CALENDAR_EVENTS,
            Calendar::CALENDAR_FREEBUSY,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code): array
    {
        $client = $this->configuredClient();

        return $client->fetchAccessTokenWithAuthCode($code);
    }

    public function configuredClient(): Client
    {
        $this->client->setClientId((string) config('services.google.client_id'));
        $this->client->setClientSecret((string) config('services.google.client_secret'));
        $this->client->setRedirectUri((string) config('services.google.redirect'));
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
        $this->client->setIncludeGrantedScopes(true);

        return $this->client;
    }
}
