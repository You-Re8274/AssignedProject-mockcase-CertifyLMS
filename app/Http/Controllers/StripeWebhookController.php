<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\UseCases\MeetingQuota\ProcessStripeWebhookEventAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function handle(Request $request, ProcessStripeWebhookEventAction $action): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');
        if (! is_string($secret) || trim($secret) === '') {
            Log::error('Stripe webhook secret is not configured.');

            return response()->json(['message' => 'Webhook is not configured.'], 503);
        }

        $signature = $request->header('Stripe-Signature');
        if (! is_string($signature) || trim($signature) === '') {
            return response()->json(['message' => 'Missing Stripe signature.'], 400);
        }

        try {
            $event = Webhook::constructEvent($request->getContent(), $signature, $secret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            Log::warning('Stripe webhook signature or payload validation failed.');

            return response()->json(['message' => 'Invalid Stripe webhook.'], 400);
        }

        $action($event);

        return response()->json(['received' => true]);
    }
}
