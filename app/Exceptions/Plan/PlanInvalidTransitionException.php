<?php

declare(strict_types=1);

namespace App\Exceptions\Plan;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PlanInvalidTransitionException extends ConflictHttpException
{
    public static function forPublish(): self
    {
        return new self('下書きのプランのみ公開できます。');
    }

    public static function forArchive(): self
    {
        return new self('公開中のプランのみアーカイブできます。');
    }

    public static function forUnarchive(): self
    {
        return new self('アーカイブ済みのプランのみ下書きに戻せます。');
    }
}
