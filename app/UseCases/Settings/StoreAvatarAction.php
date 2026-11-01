<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use App\Services\UserAvatarStorage;
use Illuminate\Http\UploadedFile;
use Throwable;

final class StoreAvatarAction
{
    public function __construct(private readonly UserAvatarStorage $storage) {}

    /**
     * @param array{avatar: UploadedFile} $data
     */
    public function __invoke(User $user, array $data): void
    {
        $oldAvatarUrl = $user->avatar_url;
        $newAvatarUrl = $this->storage->store($user, $data['avatar']);

        try {
            $user->update(['avatar_url' => $newAvatarUrl]);
        } catch (Throwable $exception) {
            $this->storage->deleteManaged($user, $newAvatarUrl);

            throw $exception;
        }

        $this->storage->deleteManaged($user, $oldAvatarUrl);
    }
}
