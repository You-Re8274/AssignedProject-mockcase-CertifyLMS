<?php

declare(strict_types=1);

namespace Tests\Support;

trait SignsStripeWebhookPayload
{
    protected function stripeSignatureHeader(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return "t={$timestamp},v1={$signature}";
    }
}
