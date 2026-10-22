<?php

declare(strict_types=1);

namespace App\UseCases\QaBoard;

use App\Models\QaThread;

final class UpdateThreadAction
{
    /**
     * @param array{title: string, body: string} $validated
     */
    public function __invoke(QaThread $thread, array $validated): QaThread
    {
        $thread->update($validated);

        return $thread;
    }
}
