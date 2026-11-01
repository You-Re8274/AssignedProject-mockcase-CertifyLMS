<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class UserAvatarStorage
{
    public function store(User $user, UploadedFile $avatar): string
    {
        $disk = Storage::disk('public');
        $directory = "avatars/{$user->id}";
        $filename = Str::uuid().'.'.$avatar->extension();
        $path = "{$directory}/{$filename}";

        if ($disk->putFileAs($directory, $avatar, $filename) === false) {
            throw new RuntimeException('アバター画像を保存できませんでした。');
        }

        return $disk->url($path);
    }

    public function deleteManaged(User $user, ?string $avatarUrl): void
    {
        if ($avatarUrl === null || $avatarUrl === '') {
            return;
        }

        $disk = Storage::disk('public');
        $directory = "avatars/{$user->id}/";
        $urlPrefix = $disk->url($directory);

        if (! str_starts_with($avatarUrl, $urlPrefix)) {
            return;
        }

        $filename = substr($avatarUrl, strlen($urlPrefix));
        if ($filename === '' || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return;
        }

        $path = $directory.$filename;
        if ($disk->exists($path) && ! $disk->delete($path)) {
            throw new RuntimeException('アバター画像を削除できませんでした。');
        }
    }
}
