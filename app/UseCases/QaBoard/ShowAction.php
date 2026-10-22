<?php

declare(strict_types=1);

namespace App\UseCases\QaBoard;

use App\Models\QaThread;

final class ShowAction
{
    public function __invoke(QaThread $thread): QaThread
    {
        return $thread->load([
            'user',
            'certification',
            'replies.user',
        ])->loadCount('replies');
    }
}
