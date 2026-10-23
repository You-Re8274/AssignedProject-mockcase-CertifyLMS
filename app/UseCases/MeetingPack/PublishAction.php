<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackInvalidTransitionException;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PublishAction
{
    public function __invoke(MeetingPack $meetingPack, User $admin): MeetingPack
    {
        return DB::transaction(function () use ($meetingPack, $admin): MeetingPack {
            $locked = MeetingPack::query()->lockForUpdate()->findOrFail($meetingPack->id);
            if ($locked->status !== MeetingPackStatus::Draft) {
                throw MeetingPackInvalidTransitionException::forPublish();
            }

            $locked->update([
                'status' => MeetingPackStatus::Published,
                'updated_by_user_id' => $admin->id,
            ]);

            return $locked->fresh();
        });
    }
}
