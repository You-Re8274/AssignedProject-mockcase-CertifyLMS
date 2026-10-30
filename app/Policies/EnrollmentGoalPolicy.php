<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;

class EnrollmentGoalPolicy
{
    public function __construct(private readonly EnrollmentPolicy $enrollmentPolicy) {}

    public function viewAny(User $user, Enrollment $enrollment): bool
    {
        return $this->enrollmentPolicy->view($user, $enrollment);
    }

    public function view(User $user, EnrollmentGoal $goal): bool
    {
        $goal->loadMissing('enrollment');

        return $goal->enrollment !== null
            && $this->enrollmentPolicy->view($user, $goal->enrollment);
    }

    public function create(User $user, Enrollment $enrollment): bool
    {
        return $this->canManage($user, $enrollment);
    }

    public function update(User $user, EnrollmentGoal $goal): bool
    {
        return $this->canManageGoal($user, $goal);
    }

    public function delete(User $user, EnrollmentGoal $goal): bool
    {
        return $this->canManageGoal($user, $goal);
    }

    public function markAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->canManageGoal($user, $goal);
    }

    public function unmarkAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->canManageGoal($user, $goal);
    }

    private function canManageGoal(User $user, EnrollmentGoal $goal): bool
    {
        $goal->loadMissing('enrollment');

        return $goal->enrollment !== null
            && $this->canManage($user, $goal->enrollment);
    }

    private function canManage(User $user, Enrollment $enrollment): bool
    {
        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress
            && $enrollment->status === EnrollmentStatus::Learning
            && $enrollment->user_id === $user->id;
    }
}
