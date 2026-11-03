<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;

class EnrollmentNotePolicy
{
    public function viewAny(User $user, Enrollment $enrollment): bool
    {
        return ! $enrollment->trashed()
            && ($user->role === UserRole::Admin || $this->isAssignedCoach($user, $enrollment));
    }

    public function view(User $user, EnrollmentNote $note): bool
    {
        $note->loadMissing('enrollment');

        return $note->enrollment !== null
            && $this->viewAny($user, $note->enrollment);
    }

    public function create(User $user, Enrollment $enrollment): bool
    {
        return $this->viewAny($user, $enrollment);
    }

    public function update(User $user, EnrollmentNote $note): bool
    {
        return $this->canManage($user, $note);
    }

    public function delete(User $user, EnrollmentNote $note): bool
    {
        return $this->canManage($user, $note);
    }

    private function canManage(User $user, EnrollmentNote $note): bool
    {
        $note->loadMissing('enrollment');
        $enrollment = $note->enrollment;

        if ($enrollment === null || ! $this->viewAny($user, $enrollment)) {
            return false;
        }

        return $user->role === UserRole::Admin
            || ($user->role === UserRole::Coach && $note->user_id === $user->id);
    }

    private function isAssignedCoach(User $user, Enrollment $enrollment): bool
    {
        if ($user->role !== UserRole::Coach) {
            return false;
        }

        if ($enrollment->relationLoaded('certification')
            && $enrollment->certification?->relationLoaded('coaches')) {
            return $enrollment->certification->coaches->contains('id', $user->id);
        }

        return $enrollment->certification()
            ->whereHas('coaches', fn ($query) => $query->where('users.id', $user->id))
            ->exists();
    }
}
