<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanInvalidTransitionException;
use App\Exceptions\Plan\PlanNotDeletableException;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlanLog;
use App\UseCases\Plan\ArchiveAction;
use App\UseCases\Plan\DestroyAction;
use App\UseCases\Plan\PublishAction;
use App\UseCases\Plan\UnarchiveAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_transitions_throw_conflict_and_preserve_status(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = Plan::factory()->draft()->create();
        $published = Plan::factory()->published()->create();
        $archived = Plan::factory()->archived()->create();

        $this->assertInvalidTransition(
            fn () => (new PublishAction)($published, $admin),
            $published,
            PlanStatus::Published,
        );
        $this->assertInvalidTransition(
            fn () => (new ArchiveAction)($draft, $admin),
            $draft,
            PlanStatus::Draft,
        );
        $this->assertInvalidTransition(
            fn () => (new UnarchiveAction)($published, $admin),
            $published,
            PlanStatus::Published,
        );
        $this->assertInvalidTransition(
            fn () => (new PublishAction)($archived, $admin),
            $archived,
            PlanStatus::Archived,
        );

        $this->assertSame(PlanStatus::Draft, $draft->fresh()->status);
    }

    private function assertInvalidTransition(callable $operation, Plan $plan, PlanStatus $expectedStatus): void
    {
        try {
            $operation();
            $this->fail('Invalid plan status transition must throw a conflict exception.');
        } catch (PlanInvalidTransitionException) {
            $this->assertSame($expectedStatus, $plan->fresh()->status);
        }
    }

    public function test_destroy_action_rejects_any_user_or_history_reference(): void
    {
        $withActiveUser = Plan::factory()->draft()->create();
        $withDeletedUser = Plan::factory()->draft()->create();
        $withHistory = Plan::factory()->draft()->create();

        User::factory()->student()->withPlan($withActiveUser)->create();
        User::factory()->student()->withPlan($withDeletedUser)->create()->delete();
        UserPlanLog::factory()->for($withHistory, 'plan')->create();

        foreach ([$withActiveUser, $withDeletedUser, $withHistory] as $plan) {
            try {
                (new DestroyAction)($plan);
                $this->fail('A referenced plan must not be deletable.');
            } catch (PlanNotDeletableException) {
                $this->assertDatabaseHas('plans', ['id' => $plan->id]);
            }
        }
    }

    public function test_destroy_action_only_deletes_unused_draft_plans(): void
    {
        $draft = Plan::factory()->draft()->create();
        $published = Plan::factory()->published()->create();
        $archived = Plan::factory()->archived()->create();

        foreach ([$published, $archived] as $plan) {
            try {
                (new DestroyAction)($plan);
                $this->fail('Only draft plans may be deleted.');
            } catch (PlanNotDeletableException) {
                $this->assertDatabaseHas('plans', ['id' => $plan->id]);
            }
        }

        (new DestroyAction)($draft);
        $this->assertDatabaseMissing('plans', ['id' => $draft->id]);
    }
}
