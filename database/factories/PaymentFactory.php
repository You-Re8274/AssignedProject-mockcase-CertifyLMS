<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
            'meeting_pack_id' => MeetingPack::factory()->published()->state([
                'name' => '3 回パック',
                'meeting_count' => 3,
                'price' => 9000,
            ]),
            'meeting_pack_name' => '3 回パック',
            'meeting_count' => 3,
            'amount' => 9000,
            'currency' => 'JPY',
            'status' => PaymentStatus::Pending->value,
            'stripe_checkout_session_id' => null,
            'stripe_payment_intent_id' => null,
            'paid_at' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Paid->value,
            'stripe_checkout_session_id' => 'cs_test_'.$this->faker->unique()->bothify('##########'),
            'stripe_payment_intent_id' => 'pi_test_'.$this->faker->unique()->bothify('##########'),
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Failed->value]);
    }

    public function canceled(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Canceled->value]);
    }
}
