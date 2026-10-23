<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanInvalidTransitionException;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PublishAction
{
    public function __invoke(Plan $plan, User $admin): Plan
    {
        return DB::transaction(function () use ($plan, $admin): Plan {
            $locked = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($locked->status !== PlanStatus::Draft) {
                throw PlanInvalidTransitionException::forPublish();
            }

            $locked->update([
                'status' => PlanStatus::Published,
                'updated_by_user_id' => $admin->id,
            ]);

            return $locked->fresh();
        });
    }
}
