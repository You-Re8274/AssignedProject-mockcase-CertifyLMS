<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;

final class UpdatePasswordAction
{
    /**
     * @param array{password: string} $data
     */
    public function __invoke(User $user, array $data): void
    {
        $user->forceFill(['password' => $data['password']])->save();
    }
}
