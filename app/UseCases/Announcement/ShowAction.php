<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Models\Announcement;

final class ShowAction
{
    public function __invoke(Announcement $announcement): Announcement
    {
        return $announcement->load(['targetCertification', 'targetUser', 'createdBy']);
    }
}
