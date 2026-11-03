<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\EnrollmentNote;

final class UpdateAction
{
    /**
     * @param array{body: string} $data
     */
    public function __invoke(EnrollmentNote $note, array $data): EnrollmentNote
    {
        $note->update($data);

        return $note->refresh();
    }
}
