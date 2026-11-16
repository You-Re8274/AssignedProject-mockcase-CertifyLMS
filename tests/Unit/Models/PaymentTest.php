<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_relations_and_purchase_snapshot_are_persisted(): void
    {
        $student = User::factory()->student()->create();
        $pack = MeetingPack::factory()->published()->withCount(3)->withPrice(9000)->create();
        $payment = Payment::factory()
            ->for($student)
            ->for($pack)
            ->paid()
            ->create([
                'meeting_pack_name' => $pack->name,
                'meeting_count' => $pack->meeting_count,
                'amount' => $pack->price,
                'currency' => 'JPY',
                'paid_at' => '2026-11-16 12:00:00',
            ]);
        $quotaTransaction = MeetingQuotaTransaction::factory()
            ->purchased($payment->meeting_count, $payment->id)
            ->create(['user_id' => $student->id]);

        $pack->update([
            'name' => '変更後のパック名',
            'meeting_count' => 10,
            'price' => 20000,
        ]);
        $payment = $payment->fresh();

        $this->assertTrue($payment->user->is($student));
        $this->assertTrue($payment->meetingPack->is($pack));
        $this->assertTrue($payment->quotaTransactions->sole()->is($quotaTransaction));
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(3, $payment->meeting_count);
        $this->assertSame(9000, $payment->amount);
        $this->assertSame('JPY', $payment->currency);
        $this->assertSame('3 回パック', $payment->meeting_pack_name);
        $this->assertInstanceOf(Carbon::class, $payment->paid_at);
    }

    public function test_checkout_session_id_must_be_unique(): void
    {
        Payment::factory()->create(['stripe_checkout_session_id' => 'cs_test_once']);

        $this->expectException(QueryException::class);
        Payment::factory()->create(['stripe_checkout_session_id' => 'cs_test_once']);
    }

    public function test_a_purchase_can_have_only_one_quota_grant_transaction(): void
    {
        $payment = Payment::factory()->create();
        MeetingQuotaTransaction::factory()->purchased(3, $payment->id)->create([
            'user_id' => $payment->user_id,
        ]);

        $this->expectException(QueryException::class);
        MeetingQuotaTransaction::factory()->purchased(3, $payment->id)->create([
            'user_id' => $payment->user_id,
        ]);
    }
}
