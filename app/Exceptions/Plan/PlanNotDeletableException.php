<?php

declare(strict_types=1);

namespace App\Exceptions\Plan;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PlanNotDeletableException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('受講者またはプラン履歴が紐づいているプランは削除できません。下書きかつ未使用のプランのみ削除できます。');
    }
}
