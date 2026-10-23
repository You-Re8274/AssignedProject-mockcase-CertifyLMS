<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackNotDeletableException;
use App\Models\MeetingPack;
use Illuminate\Support\Facades\DB;

final class DestroyAction
{
    public function __invoke(MeetingPack $meetingPack): void
    {
        DB::transaction(function () use ($meetingPack): void {
            $locked = MeetingPack::query()->lockForUpdate()->findOrFail($meetingPack->id);
            if ($locked->status === MeetingPackStatus::Published) {
                throw new MeetingPackNotDeletableException;
            }

            $locked->delete();
        });
    }
}
