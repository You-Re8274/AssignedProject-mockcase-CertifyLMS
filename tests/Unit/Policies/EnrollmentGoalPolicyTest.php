<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use App\Policies\EnrollmentGoalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentGoalPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewing_delegates_to_enrollment_access_scope(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $otherCoach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->assignCoach($certification, $coach, $admin);
        $enrollment = Enrollment::factory()->for($owner)->for($certification)->learning()->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create();
        $policy = app(EnrollmentGoalPolicy::class);

        $this->assertTrue($policy->viewAny($owner, $enrollment));
        $this->assertTrue($policy->viewAny($coach, $enrollment));
        $this->assertTrue($policy->viewAny($admin, $enrollment));
        $this->assertFalse($policy->viewAny($otherStudent, $enrollment));
        $this->assertFalse($policy->viewAny($otherCoach, $enrollment));

        $this->assertTrue($policy->view($owner, $goal));
        $this->assertTrue($policy->view($coach, $goal));
        $this->assertTrue($policy->view($admin, $goal));
        $this->assertFalse($policy->view($otherStudent, $goal));
        $this->assertFalse($policy->view($otherCoach, $goal));
    }

    public function test_only_active_owner_of_learning_enrollment_can_manage_goals(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->for($enrollment)->create();
        $policy = app(EnrollmentGoalPolicy::class);

        $this->assertTrue($policy->create($owner, $enrollment));
        $this->assertTrue($policy->update($owner, $goal));
        $this->assertTrue($policy->delete($owner, $goal));
        $this->assertTrue($policy->markAchieved($owner, $goal));
        $this->assertTrue($policy->unmarkAchieved($owner, $goal));

        foreach ([$otherStudent, $coach, $admin] as $user) {
            $this->assertFalse($policy->create($user, $enrollment));
            $this->assertFalse($policy->update($user, $goal));
            $this->assertFalse($policy->delete($user, $goal));
            $this->assertFalse($policy->markAchieved($user, $goal));
            $this->assertFalse($policy->unmarkAchieved($user, $goal));
        }
    }

    public function test_management_is_denied_for_inactive_student_or_non_learning_enrollment(): void
    {
        $graduatedStudent = User::factory()->student()->graduated()->create();
        $graduatedEnrollment = Enrollment::factory()->for($graduatedStudent)->learning()->create();
        $learningStudent = User::factory()->student()->inProgress()->create();
        $passedEnrollment = Enrollment::factory()->for($learningStudent)->passed()->create();
        $policy = app(EnrollmentGoalPolicy::class);

        $this->assertFalse($policy->create($graduatedStudent, $graduatedEnrollment));
        $this->assertFalse($policy->create($learningStudent, $passedEnrollment));
    }

    private function assignCoach(Certification $certification, User $coach, User $admin): void
    {
        CertificationCoachAssignment::query()->create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
    }
}
