<?php

declare(strict_types=1);

namespace App\Exceptions\MeetingPack;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class MeetingPackNotDeletableException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('公開中の面談パックは削除できません。先にアーカイブしてください。');
    }
}
