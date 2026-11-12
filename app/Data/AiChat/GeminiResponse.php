<?php

declare(strict_types=1);

namespace App\Data\AiChat;

final readonly class GeminiResponse
{
    public function __construct(
        public string $content,
        public string $modelName,
        public ?int $inputTokens,
        public ?int $outputTokens,
    ) {}
}
