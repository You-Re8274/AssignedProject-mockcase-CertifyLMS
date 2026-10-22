<?php

declare(strict_types=1);

namespace App\UseCases\QaBoard;

use App\Models\QaReply;

final class UpdateReplyAction
{
    /**
     * @param array{body: string} $validated
     */
    public function __invoke(QaReply $reply, array $validated): QaReply
    {
        $reply->update($validated);

        return $reply;
    }
}
