<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Enums\PaymentStatus;
use App\Exceptions\MeetingQuota\StripeCheckoutUnavailableException;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\MeetingQuota\StripeCheckoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

final class CreateCheckoutSessionAction
{
    public function __construct(
        private readonly StripeCheckoutService $stripeCheckout,
    ) {}

    public function __invoke(User $user, string $meetingPackId): string
    {
        $secret = config('services.stripe.secret');
        if (! is_string($secret) || trim($secret) === '') {
            Log::error('Stripe Checkout is not configured.');

            throw new StripeCheckoutUnavailableException;
        }

        $payment = DB::transaction(function () use ($user, $meetingPackId): Payment {
            $pack = MeetingPack::query()
                ->published()
                ->lockForUpdate()
                ->findOrFail($meetingPackId);

            return Payment::query()->create([
                'user_id' => $user->id,
                'meeting_pack_id' => $pack->id,
                'meeting_pack_name' => $pack->name,
                'meeting_count' => $pack->meeting_count,
                'amount' => $pack->price,
                'currency' => 'JPY',
                'status' => PaymentStatus::Pending,
            ]);
        });

        try {
            $session = $this->stripeCheckout->createSession($payment);
        } catch (ApiErrorException $exception) {
            Log::error('Stripe Checkout session creation failed.', [
                'payment_id' => $payment->id,
                'stripe_request_id' => $exception->getRequestId(),
                'http_status' => $exception->getHttpStatus(),
            ]);

            throw new StripeCheckoutUnavailableException($exception);
        }

        if (! is_string($session->id) || $session->id === '' || ! is_string($session->url) || $session->url === '') {
            Log::error('Stripe Checkout returned a session without an ID or URL.', [
                'payment_id' => $payment->id,
            ]);

            throw new StripeCheckoutUnavailableException;
        }

        $payment->forceFill([
            'stripe_checkout_session_id' => $session->id,
        ])->save();

        return $session->url;
    }
}
