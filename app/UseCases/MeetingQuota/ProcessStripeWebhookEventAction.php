<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Event;

final class ProcessStripeWebhookEventAction
{
    public function __invoke(Event $event): void
    {
        if (! in_array($event->type, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
            'checkout.session.expired',
        ], true)) {
            return;
        }

        $session = data_get($event, 'data.object');
        if (! is_object($session)) {
            $this->logIgnored($event, 'missing_checkout_object');

            return;
        }

        $sessionId = data_get($session, 'id');
        $paymentId = data_get($session, 'metadata.payment_id');

        if (! is_string($sessionId) || $sessionId === '' || ! is_string($paymentId) || $paymentId === '') {
            $this->logIgnored($event, 'missing_checkout_identifiers');

            return;
        }

        DB::transaction(function () use ($event, $session, $sessionId, $paymentId): void {
            $payment = Payment::query()
                ->where('stripe_checkout_session_id', $sessionId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                $payment = Payment::query()
                    ->whereKey($paymentId)
                    ->lockForUpdate()
                    ->first();
            }

            if ($payment === null) {
                $this->logIgnored($event, 'payment_not_found', $sessionId, $paymentId);

                return;
            }

            if (
                $payment->id !== $paymentId
                || (string) data_get($session, 'metadata.user_id') !== $payment->user_id
                || (string) data_get($session, 'client_reference_id') !== $payment->id
                || ($payment->stripe_checkout_session_id !== null
                    && $payment->stripe_checkout_session_id !== $sessionId)
            ) {
                $this->logIgnored($event, 'checkout_metadata_mismatch', $sessionId, $payment->id);

                return;
            }

            if ($payment->stripe_checkout_session_id === null) {
                $payment->stripe_checkout_session_id = $sessionId;
            }

            if ($event->type === 'checkout.session.expired') {
                if ($payment->status === PaymentStatus::Pending) {
                    $payment->status = PaymentStatus::Canceled;
                    $payment->save();
                }

                return;
            }

            if ($event->type === 'checkout.session.async_payment_failed') {
                if ($payment->status === PaymentStatus::Pending) {
                    $payment->status = PaymentStatus::Failed;
                    $payment->save();
                }

                return;
            }

            if (data_get($session, 'payment_status') !== 'paid') {
                $payment->save();

                return;
            }

            if (
                data_get($session, 'mode') !== 'payment'
                || ! is_numeric(data_get($session, 'amount_total'))
                || (int) data_get($session, 'amount_total') !== $payment->amount
                || strtolower((string) data_get($session, 'currency')) !== strtolower($payment->currency)
            ) {
                $this->logIgnored($event, 'checkout_amount_or_currency_mismatch', $sessionId, $payment->id);

                return;
            }

            if ($payment->status !== PaymentStatus::Pending) {
                return;
            }

            $paymentIntent = data_get($session, 'payment_intent');
            $paymentIntentId = is_string($paymentIntent)
                ? $paymentIntent
                : data_get($paymentIntent, 'id');

            $payment->forceFill([
                'status' => PaymentStatus::Paid,
                'stripe_payment_intent_id' => is_string($paymentIntentId) ? $paymentIntentId : null,
                'paid_at' => now(),
            ])->save();

            MeetingQuotaTransaction::query()->create([
                'user_id' => $payment->user_id,
                'type' => MeetingQuotaTransactionType::Purchased,
                'amount' => $payment->meeting_count,
                'related_payment_id' => $payment->id,
                'occurred_at' => now(),
            ]);
        });
    }

    private function logIgnored(
        Event $event,
        string $reason,
        ?string $sessionId = null,
        ?string $paymentId = null,
    ): void {
        Log::warning('Stripe checkout webhook was ignored.', [
            'event_id' => $event->id,
            'event_type' => $event->type,
            'reason' => $reason,
            'session_id' => $sessionId,
            'payment_id' => $paymentId,
        ]);
    }
}
