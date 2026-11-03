<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\EnrollmentNote;

final class DestroyAction
{
    public function __invoke(EnrollmentNote $note): void
    {
        $note->delete();
    }
}
