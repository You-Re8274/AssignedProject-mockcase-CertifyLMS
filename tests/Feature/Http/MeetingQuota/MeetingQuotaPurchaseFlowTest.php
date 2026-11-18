<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\SignsStripeWebhookPayload;
use Tests\TestCase;

#[Group('external-api')]
class MeetingQuotaPurchaseFlowTest extends TestCase
{
    use RefreshDatabase;
    use SignsStripeWebhookPayload;

    private const WEBHOOK_SECRET = 'whsec_purchase_flow_test_secret';

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_checkout_to_signed_webhook_grants_quota_once(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_purchase_flow',
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
        ]);

        $student = User::factory()->student()->inProgress()->create();
        $pack = MeetingPack::factory()->published()->create([
            'name' => '4回パック',
            'meeting_count' => 4,
            'price' => 14000,
        ]);
        $quota = app(MeetingQuotaService::class);
        $remainingBefore = $quota->remaining($student);

        $httpClient = Mockery::mock(ClientInterface::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->withArgs(function (
                string $method,
                string $url,
                array $headers,
                array $params,
                bool $hasFile,
                string $apiMode,
            ) use ($student): bool {
                $this->assertSame('post', $method);
                $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $url);
                $this->assertContains('Authorization: Bearer sk_test_purchase_flow', $headers);
                $this->assertFalse($hasFile);
                $this->assertSame('v1', $apiMode);
                $this->assertSame('payment', $params['mode']);
                $this->assertSame('jpy', $params['line_items'][0]['price_data']['currency']);
                $this->assertSame(14000, $params['line_items'][0]['price_data']['unit_amount']);
                $this->assertSame('4回パック', $params['line_items'][0]['price_data']['product_data']['name']);
                $this->assertSame($student->id, $params['metadata']['user_id']);
                $this->assertSame($params['metadata']['payment_id'], $params['client_reference_id']);

                return true;
            })
            ->andReturn([
                json_encode([
                    'id' => 'cs_test_purchase_flow',
                    'object' => 'checkout.session',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_purchase_flow',
                ], JSON_THROW_ON_ERROR),
                200,
                ['request-id' => 'req_test_purchase_flow'],
            ]);
        ApiRequestor::setHttpClient($httpClient);

        $this->actingAs($student)
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_purchase_flow');

        $payment = Payment::query()->sole();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(4, $payment->meeting_count);
        $this->assertSame(14000, $payment->amount);
        $this->assertSame('cs_test_purchase_flow', $payment->stripe_checkout_session_id);
        $this->assertSame($remainingBefore, $quota->remaining($student));
        $this->assertDatabaseCount('meeting_quota_transactions', 0);

        $this->get(route('meeting-quota.success', ['session_id' => $payment->stripe_checkout_session_id]))
            ->assertOk()
            ->assertViewHas('payment', fn (Payment $viewPayment) => $viewPayment->is($payment));
        $this->assertSame($remainingBefore, $quota->remaining($student));

        $session = [
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
            'payment_intent' => 'pi_test_purchase_flow',
            'status' => 'complete',
        ];
        $this->sendCompletedEvent($session, 'evt_test_purchase_flow')
            ->assertOk()
            ->assertExactJson(['received' => true]);
        $this->sendCompletedEvent($session, 'evt_test_purchase_flow_redelivery')
            ->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame('pi_test_purchase_flow', $payment->fresh()->stripe_payment_intent_id);
        $this->assertSame(1, $payment->quotaTransactions()->count());
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
            'amount' => 4,
            'related_payment_id' => $payment->id,
        ]);
        $this->assertSame($remainingBefore + 4, $quota->remaining($student));
    }

    /**
     * @param array<string, mixed> $session
     */
    private function sendCompletedEvent(array $session, string $eventId): TestResponse
    {
        $payload = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'created' => time(),
            'data' => ['object' => $session],
            'livemode' => false,
            'pending_webhooks' => 1,
            'type' => 'checkout.session.completed',
        ], JSON_THROW_ON_ERROR);

        return $this->call(
            'POST',
            route('webhooks.stripe'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->stripeSignatureHeader($payload, self::WEBHOOK_SECRET),
            ],
            $payload,
        );
    }
}
