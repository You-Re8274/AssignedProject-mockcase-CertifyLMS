<?php

declare(strict_types=1);

namespace App\UseCases\QaBoard;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;

final class StoreReplyAction
{
    /**
     * @param array{body: string} $validated
     */
    public function __invoke(User $author, QaThread $thread, array $validated): QaReply
    {
        return $thread->replies()->create([
            'user_id' => $author->id,
            'body' => $validated['body'],
        ]);
    }
}
