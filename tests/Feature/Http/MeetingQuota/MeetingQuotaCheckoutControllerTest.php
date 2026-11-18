<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingQuota;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

#[Group('external-api')]
class MeetingQuotaCheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_student_sees_only_published_packs(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $published = MeetingPack::factory()->published()->create(['name' => '公開パック']);
        MeetingPack::factory()->draft()->create(['name' => '下書きパック']);
        MeetingPack::factory()->archived()->create(['name' => '終了パック']);

        $this->actingAs($student)
            ->get(route('meeting-quota.checkout.select'))
            ->assertOk()
            ->assertViewIs('meeting-quota.checkout-select')
            ->assertViewHas('plans', fn ($plans) => $plans->contains('id', $published->id))
            ->assertSee('公開パック')
            ->assertDontSee('下書きパック')
            ->assertDontSee('終了パック');
    }

    public function test_student_checkout_uses_published_pack_snapshot_and_redirects_to_stripe(): void
    {
        config(['services.stripe.secret' => 'sk_test_fake']);
        $student = User::factory()->student()->inProgress()->create();
        $pack = MeetingPack::factory()->published()->create([
            'name' => '5回パック',
            'meeting_count' => 5,
            'price' => 12000,
        ]);
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
                $this->assertContains('Authorization: Bearer sk_test_fake', $headers);
                $this->assertFalse($hasFile);
                $this->assertSame('v1', $apiMode);
                $this->assertSame('payment', $params['mode']);
                $this->assertSame(strtolower('JPY'), $params['line_items'][0]['price_data']['currency']);
                $this->assertSame(12000, $params['line_items'][0]['price_data']['unit_amount']);
                $this->assertSame('5回パック', $params['line_items'][0]['price_data']['product_data']['name']);
                $this->assertSame(1, $params['line_items'][0]['quantity']);
                $this->assertSame($student->id, $params['metadata']['user_id']);
                $this->assertStringContainsString('/meeting-quota/success?session_id={CHECKOUT_SESSION_ID}', $params['success_url']);
                $this->assertSame(
                    route('meeting-quota.checkout.select', ['checkout' => 'canceled']),
                    $params['cancel_url'],
                );

                return true;
            })
            ->andReturn([
                json_encode([
                    'id' => 'cs_test_checkout_session',
                    'object' => 'checkout.session',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_checkout_session',
                ], JSON_THROW_ON_ERROR),
                200,
                ['request-id' => 'req_test_checkout'],
            ]);
        ApiRequestor::setHttpClient($httpClient);

        $this->actingAs($student)
            ->from(route('meeting-quota.checkout.select'))
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_checkout_session');

        $payment = Payment::query()->sole();
        $this->assertSame($student->id, $payment->user_id);
        $this->assertSame($pack->id, $payment->meeting_pack_id);
        $this->assertSame('5回パック', $payment->meeting_pack_name);
        $this->assertSame(5, $payment->meeting_count);
        $this->assertSame(12000, $payment->amount);
        $this->assertSame('JPY', $payment->currency);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('cs_test_checkout_session', $payment->stripe_checkout_session_id);
    }

    public function test_unpublished_pack_cannot_be_purchased_by_direct_post(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $this->actingAs($student)
            ->from(route('meeting-quota.checkout.select'))
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])
            ->assertRedirect(route('meeting-quota.checkout.select'))
            ->assertSessionHasErrors('meeting_pack_id');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_checkout_requires_stripe_secret_without_creating_a_payment(): void
    {
        config(['services.stripe.secret' => null]);
        $student = User::factory()->student()->inProgress()->create();
        $pack = MeetingPack::factory()->published()->create();

        $this->actingAs($student)
            ->from(route('meeting-quota.checkout.select'))
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])
            ->assertRedirect(route('meeting-quota.checkout.select'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_non_student_and_non_learning_users_cannot_access_checkout(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $this->actingAs($coach)
            ->get(route('meeting-quota.checkout.select'))
            ->assertForbidden();

        $graduated = User::factory()->student()->graduated()->create();
        $this->actingAs($graduated)
            ->get(route('meeting-quota.checkout.select'))
            ->assertForbidden();
    }

    public function test_success_page_displays_only_the_current_students_payment(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $payment = Payment::factory()
            ->for($student)
            ->paid()
            ->create(['stripe_checkout_session_id' => 'cs_test_success']);

        $this->actingAs($student)
            ->get(route('meeting-quota.success', ['session_id' => 'cs_test_success']))
            ->assertOk()
            ->assertViewIs('meeting-quota.success')
            ->assertViewHas('payment', fn (Payment $viewPayment) => $viewPayment->is($payment))
            ->assertSee($payment->meeting_count.' 回')
            ->assertSee('¥'.number_format($payment->amount))
            ->assertSee($payment->status->label());

        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_success_page_does_not_expose_another_students_payment(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $payment = Payment::factory()
            ->for($owner)
            ->paid()
            ->create(['stripe_checkout_session_id' => 'cs_test_private']);
        $otherStudent = User::factory()->student()->inProgress()->create();

        $this->actingAs($otherStudent)
            ->get(route('meeting-quota.success', ['session_id' => $payment->stripe_checkout_session_id]))
            ->assertNotFound();
    }

    public function test_checkout_cancellation_returns_to_selection_without_changing_quota(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $payment = Payment::factory()->for($student)->create();

        $this->actingAs($student)
            ->get(route('meeting-quota.checkout.select', ['checkout' => 'canceled']))
            ->assertRedirect(route('meeting-quota.checkout.select'))
            ->assertSessionHas('info', '決済はキャンセルされました。面談回数は変更されていません。');

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }
}
