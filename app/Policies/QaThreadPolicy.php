<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Models\QaThread;
use App\Models\User;

class QaThreadPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Student, UserRole::Coach], true);
    }

    public function view(User $user, QaThread $thread): bool
    {
        if (! in_array($user->role, [UserRole::Student, UserRole::Coach], true)) {
            return false;
        }

        $certification = $thread->certification;
        if ($certification === null || $certification->status !== CertificationStatus::Published) {
            return false;
        }

        return $user->role !== UserRole::Coach
            || $certification->coaches()->whereKey($user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    public function update(User $user, QaThread $thread): bool
    {
        return $user->role === UserRole::Student
            && $thread->user_id === $user->id
            && $this->view($user, $thread);
    }

    public function delete(User $user, QaThread $thread): bool
    {
        return $this->update($user, $thread)
            && ! $thread->replies()->exists();
    }

    public function resolve(User $user, QaThread $thread): bool
    {
        return $thread->status === QaThreadStatus::Open
            && $this->update($user, $thread);
    }

    public function unresolve(User $user, QaThread $thread): bool
    {
        return $thread->status === QaThreadStatus::Resolved
            && $this->update($user, $thread);
    }
}
