<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use RuntimeException;

final class AiChatDailyLimitExceededException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('AI chat daily request limit exceeded.');
    }
}
