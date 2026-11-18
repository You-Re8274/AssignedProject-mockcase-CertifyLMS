<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\MeetingQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\SignsStripeWebhookPayload;
use Tests\TestCase;

#[Group('external-api')]
class StripeWebhookControllerTest extends TestCase
{
    use RefreshDatabase;
    use SignsStripeWebhookPayload;

    private const WEBHOOK_SECRET = 'whsec_test_signature_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    public function test_paid_checkout_marks_payment_and_grants_quota_exactly_once(): void
    {
        $payment = Payment::factory()->create([
            'stripe_checkout_session_id' => 'cs_test_paid',
        ]);
        $remainingBefore = app(MeetingQuotaService::class)->remaining($payment->user);
        $session = $this->paidSession($payment);

        $this->sendEvent('checkout.session.completed', $session, 'evt_test_paid')
            ->assertOk()
            ->assertExactJson(['received' => true]);
        $this->sendEvent('checkout.session.completed', $session, 'evt_test_paid_redelivery')
            ->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('pi_test_paid', $payment->stripe_payment_intent_id);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(1, $payment->quotaTransactions()->count());
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $payment->user_id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
            'amount' => $payment->meeting_count,
            'related_payment_id' => $payment->id,
        ]);
        $this->assertSame(
            $remainingBefore + $payment->meeting_count,
            app(MeetingQuotaService::class)->remaining($payment->user),
        );
    }

    public function test_async_payment_success_is_granted_but_unpaid_completion_is_not(): void
    {
        $payment = Payment::factory()->create([
            'stripe_checkout_session_id' => 'cs_test_async',
        ]);
        $unpaidSession = $this->paidSession($payment);
        $unpaidSession['payment_status'] = 'unpaid';

        $this->sendEvent('checkout.session.completed', $unpaidSession, 'evt_test_unpaid')
            ->assertOk();
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);

        $this->sendEvent('checkout.session.async_payment_succeeded', $this->paidSession($payment), 'evt_test_async_paid')
            ->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(1, $payment->quotaTransactions()->count());
    }

    public function test_expired_and_failed_sessions_update_status_without_granting_quota(): void
    {
        $expiredPayment = Payment::factory()->create([
            'stripe_checkout_session_id' => 'cs_test_expired',
        ]);
        $failedPayment = Payment::factory()->create([
            'stripe_checkout_session_id' => 'cs_test_failed',
        ]);

        $this->sendEvent(
            'checkout.session.expired',
            $this->paidSession($expiredPayment),
            'evt_test_expired',
        )->assertOk();
        $this->sendEvent(
            'checkout.session.async_payment_failed',
            $this->paidSession($failedPayment),
            'evt_test_failed',
        )->assertOk();

        $this->assertSame(PaymentStatus::Canceled, $expiredPayment->fresh()->status);
        $this->assertSame(PaymentStatus::Failed, $failedPayment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_invalid_signature_is_rejected_without_changing_payment(): void
    {
        $payment = Payment::factory()->create([
            'stripe_checkout_session_id' => 'cs_test_invalid_signature',
        ]);
        $payload = $this->eventPayload(
            'checkout.session.completed',
            $this->paidSession($payment),
            'evt_test_invalid_signature',
        );

        $this->postRawWebhook($payload, 't='.time().',v1=invalid-signature')
            ->assertBadRequest();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->call(
            'POST',
            route('webhooks.stripe'),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}',
        )->assertBadRequest();
    }

    public function test_malformed_signed_payload_is_rejected(): void
    {
        $payload = '{invalid';

        $this->postRawWebhook($payload, $this->stripeSignatureHeader($payload, self::WEBHOOK_SECRET))
            ->assertBadRequest();
    }

    public function test_checkout_amount_mismatch_is_acknowledged_but_not_credited(): void
    {
        $payment = Payment::factory()->create([
            'stripe_checkout_session_id' => 'cs_test_wrong_amount',
        ]);
        $session = $this->paidSession($payment);
        $session['amount_total']++;

        $this->sendEvent('checkout.session.completed', $session, 'evt_test_wrong_amount')
            ->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_checkout_metadata_mismatch_is_acknowledged_but_not_credited(): void
    {
        $payment = Payment::factory()->create([
            'stripe_checkout_session_id' => 'cs_test_wrong_owner',
        ]);
        $session = $this->paidSession($payment);
        $session['metadata']['user_id'] = 'another-user-id';

        $this->sendEvent('checkout.session.completed', $session, 'evt_test_wrong_owner')
            ->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_session_and_metadata_cannot_select_different_purchases(): void
    {
        $sessionOwner = Payment::factory()->create([
            'stripe_checkout_session_id' => 'cs_test_existing_session',
        ]);
        $metadataOwner = Payment::factory()->create();
        $session = $this->paidSession($metadataOwner);
        $session['id'] = $sessionOwner->stripe_checkout_session_id;

        $this->sendEvent('checkout.session.completed', $session, 'evt_test_conflicting_purchase')
            ->assertOk();

        $this->assertSame(PaymentStatus::Pending, $sessionOwner->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $metadataOwner->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_payment_is_found_by_signed_metadata_if_session_id_has_not_been_saved_yet(): void
    {
        $payment = Payment::factory()->create([
            'stripe_checkout_session_id' => null,
        ]);
        $session = $this->paidSession($payment);
        $session['id'] = 'cs_test_session_save_race';

        $this->sendEvent('checkout.session.completed', $session, 'evt_test_session_save_race')
            ->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame('cs_test_session_save_race', $payment->fresh()->stripe_checkout_session_id);
        $this->assertSame(1, $payment->quotaTransactions()->count());
    }

    public function test_unhandled_events_are_acknowledged_without_side_effects(): void
    {
        $payment = Payment::factory()->create([
            'stripe_checkout_session_id' => 'cs_test_unhandled',
        ]);

        $this->sendEvent('payment_intent.succeeded', $this->paidSession($payment), 'evt_test_unhandled')
            ->assertOk()
            ->assertExactJson(['received' => true]);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_missing_webhook_secret_returns_service_unavailable(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        $this->post(route('webhooks.stripe'))
            ->assertServiceUnavailable();
    }

    /**
     * @return array<string, mixed>
     */
    private function paidSession(Payment $payment): array
    {
        return [
            'id' => $payment->stripe_checkout_session_id,
            'object' => 'checkout.session',
            'client_reference_id' => $payment->id,
            'metadata' => [
                'payment_id' => $payment->id,
                'user_id' => $payment->user_id,
            ],
            'mode' => 'payment',
            'payment_status' => 'paid',
            'amount_total' => $payment->amount,
            'currency' => strtolower($payment->currency),
            'payment_intent' => 'pi_test_paid',
            'status' => 'complete',
        ];
    }

    private function sendEvent(string $type, array $session, string $eventId): TestResponse
    {
        $payload = $this->eventPayload($type, $session, $eventId);

        return $this->postRawWebhook($payload, $this->stripeSignatureHeader($payload, self::WEBHOOK_SECRET));
    }

    /**
     * @param array<string, mixed> $session
     */
    private function eventPayload(string $type, array $session, string $eventId): string
    {
        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'created' => time(),
            'data' => ['object' => $session],
            'livemode' => false,
            'pending_webhooks' => 1,
            'type' => $type,
        ], JSON_THROW_ON_ERROR);
    }

    private function postRawWebhook(string $payload, string $signature): TestResponse
    {
        return $this->call(
            'POST',
            route('webhooks.stripe'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signature,
            ],
            $payload,
        );
    }
}
