<?php

declare(strict_types=1);

namespace App\UseCases\QaBoard;

use App\Models\QaThread;
use App\Models\User;

final class StoreThreadAction
{
    /**
     * @param array{certification_id: string, title: string, body: string} $validated
     */
    public function __invoke(User $student, array $validated): QaThread
    {
        return QaThread::query()->create([
            'certification_id' => $validated['certification_id'],
            'user_id' => $student->id,
            'title' => $validated['title'],
            'body' => $validated['body'],
        ]);
    }
}
