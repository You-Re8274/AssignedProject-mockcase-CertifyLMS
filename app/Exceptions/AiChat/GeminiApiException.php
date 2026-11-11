<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use RuntimeException;

final class GeminiApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $upstreamStatus = null,
        public readonly string $reason = 'api_error',
    ) {
        parent::__construct($message);
    }
}
