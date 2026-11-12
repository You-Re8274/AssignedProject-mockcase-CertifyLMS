<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiChatDailyUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiChatDailyUsage>
 */
class AiChatDailyUsageFactory extends Factory
{
    protected $model = AiChatDailyUsage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
            'usage_date' => today(),
            'request_count' => 0,
        ];
    }
}
