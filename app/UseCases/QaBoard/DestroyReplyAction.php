<?php

declare(strict_types=1);

namespace App\UseCases\QaBoard;

use App\Models\QaReply;

final class DestroyReplyAction
{
    public function __invoke(QaReply $reply): void
    {
        $reply->delete();
    }
}
