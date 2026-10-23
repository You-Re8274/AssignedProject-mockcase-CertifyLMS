<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanNotDeletableException;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlanLog;
use Illuminate\Support\Facades\DB;

final class DestroyAction
{
    public function __invoke(Plan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $locked = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            $hasUserReferences = User::withTrashed()
                ->where('plan_id', $locked->id)
                ->exists();
            $hasPlanHistory = UserPlanLog::query()
                ->where('plan_id', $locked->id)
                ->exists();

            if ($locked->status !== PlanStatus::Draft || $hasUserReferences || $hasPlanHistory) {
                throw new PlanNotDeletableException;
            }

            $locked->delete();
        });
    }
}
