<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use App\Services\UserAvatarStorage;

final class DestroyAvatarAction
{
    public function __construct(private readonly UserAvatarStorage $storage) {}

    public function __invoke(User $user): void
    {
        $avatarUrl = $user->avatar_url;
        $user->update(['avatar_url' => null]);
        $this->storage->deleteManaged($user, $avatarUrl);
    }
}
