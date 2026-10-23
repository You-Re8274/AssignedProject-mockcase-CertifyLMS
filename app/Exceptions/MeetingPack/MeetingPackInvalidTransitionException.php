<?php

declare(strict_types=1);

namespace App\Exceptions\MeetingPack;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class MeetingPackInvalidTransitionException extends ConflictHttpException
{
    public static function forPublish(): self
    {
        return new self('下書き状態の面談パックのみ公開できます。');
    }

    public static function forArchive(): self
    {
        return new self('公開中の面談パックのみアーカイブできます。');
    }

    public static function forUnarchive(): self
    {
        return new self('アーカイブ状態の面談パックのみ下書きに戻せます。');
    }

    private function __construct(string $message)
    {
        parent::__construct($message);
    }
}
