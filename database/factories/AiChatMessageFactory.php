<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiChatMessage>
 */
class AiChatMessageFactory extends Factory
{
    protected $model = AiChatMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_chat_conversation_id' => AiChatConversation::factory(),
            'role' => AiChatMessageRole::User->value,
            'status' => AiChatMessageStatus::Completed->value,
            'content' => fake()->realText(120),
            'model_name' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'response_time_ms' => null,
            'error_detail' => null,
        ];
    }

    public function fromAssistant(): static
    {
        return $this->state(fn () => [
            'role' => AiChatMessageRole::Assistant->value,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'role' => AiChatMessageRole::Assistant->value,
            'status' => AiChatMessageStatus::Pending->value,
            'content' => '',
        ]);
    }

    public function failed(string $detail = 'API request failed'): static
    {
        return $this->state(fn () => [
            'role' => AiChatMessageRole::Assistant->value,
            'status' => AiChatMessageStatus::Error->value,
            'content' => '',
            'error_detail' => $detail,
        ]);
    }
}
