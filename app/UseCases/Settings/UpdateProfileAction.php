<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Enums\UserRole;
use App\Models\User;

final class UpdateProfileAction
{
    /**
     * @param array{name: string, bio?: ?string, meeting_url?: ?string} $data
     */
    public function __invoke(User $user, array $data): void
    {
        $attributes = [
            'name' => $data['name'],
            'bio' => $data['bio'] ?? null,
        ];

        if ($user->role === UserRole::Coach && array_key_exists('meeting_url', $data)) {
            $attributes['meeting_url'] = $data['meeting_url'];
        }

        $user->update($attributes);
    }
}
