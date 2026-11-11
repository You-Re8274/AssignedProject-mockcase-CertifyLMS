<?php

declare(strict_types=1);

namespace App\Data\AiChat;

use App\Models\AiChatConversation;
use App\Models\AiChatMessage;

final readonly class AiChatExchange
{
    public function __construct(
        public AiChatMessage $userMessage,
        public AiChatMessage $assistantMessage,
        public AiChatConversation $conversation,
    ) {}
}
