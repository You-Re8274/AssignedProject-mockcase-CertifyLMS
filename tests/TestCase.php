<?php

declare(strict_types=1);

namespace Tests;

use Google\Client as GoogleClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $googleClient = new GoogleClient;
        $googleClient->setHttpClient(new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([])),
        ]));
        $this->app->instance(GoogleClient::class, $googleClient);

        $stripeClient = Mockery::mock(ClientInterface::class);
        $stripeClient->shouldNotReceive('request');
        ApiRequestor::setHttpClient($stripeClient);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }
}
