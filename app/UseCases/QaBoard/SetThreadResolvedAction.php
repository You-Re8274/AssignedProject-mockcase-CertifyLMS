<?php

declare(strict_types=1);

namespace App\UseCases\QaBoard;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;

final class SetThreadResolvedAction
{
    public function __invoke(QaThread $thread, bool $resolved): QaThread
    {
        $thread->update([
            'status' => $resolved ? QaThreadStatus::Resolved : QaThreadStatus::Open,
            'resolved_at' => $resolved ? now() : null,
        ]);

        return $thread;
    }
}
