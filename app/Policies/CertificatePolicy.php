<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Certificate;
use App\Models\User;

class CertificatePolicy
{
    public function download(User $user, Certificate $certificate): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        if ($user->role === UserRole::Student) {
            return $certificate->user_id === $user->id;
        }

        if ($user->role !== UserRole::Coach) {
            return false;
        }

        $certificate->loadMissing('certification');

        return $certificate->certification?->coaches()
            ->whereKey($user->id)
            ->exists() ?? false;
    }
}
