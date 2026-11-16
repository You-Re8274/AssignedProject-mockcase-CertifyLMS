<?php

declare(strict_types=1);

namespace App\Services\MeetingQuota;

use App\Models\Payment;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

final class StripeCheckoutService
{
    public function createSession(Payment $payment): Session
    {
        $client = new StripeClient((string) config('services.stripe.secret'));

        return $client->checkout->sessions->create([
            'mode' => 'payment',
            'client_reference_id' => $payment->id,
            'customer_email' => $payment->user->email,
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($payment->currency),
                    'unit_amount' => $payment->amount,
                    'product_data' => [
                        'name' => $payment->meeting_pack_name,
                    ],
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'payment_id' => $payment->id,
                'user_id' => $payment->user_id,
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id,
                ],
            ],
            'success_url' => url('/meeting-quota/success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('meeting-quota.checkout.select', ['checkout' => 'canceled']),
        ], [
            'idempotency_key' => 'meeting-quota-checkout-'.$payment->id,
        ]);
    }
}
