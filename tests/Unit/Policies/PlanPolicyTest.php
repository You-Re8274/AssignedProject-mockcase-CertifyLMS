<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Plan;
use App\Models\User;
use App\Policies\PlanPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_abilities_are_admin_only(): void
    {
        $policy = new PlanPolicy;
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $plan = Plan::factory()->create();

        $abilities = [
            'viewAny' => fn (User $user): bool => $policy->viewAny($user),
            'view' => fn (User $user): bool => $policy->view($user, $plan),
            'create' => fn (User $user): bool => $policy->create($user),
            'update' => fn (User $user): bool => $policy->update($user, $plan),
            'delete' => fn (User $user): bool => $policy->delete($user, $plan),
            'publish' => fn (User $user): bool => $policy->publish($user, $plan),
            'archive' => fn (User $user): bool => $policy->archive($user, $plan),
            'unarchive' => fn (User $user): bool => $policy->unarchive($user, $plan),
        ];

        foreach ($abilities as $ability => $check) {
            $this->assertTrue($check($admin), "{$ability} should be allowed to admin");
            $this->assertFalse($check($student), "{$ability} should be denied to student");
            $this->assertFalse($check($coach), "{$ability} should be denied to coach");
        }
    }
}
