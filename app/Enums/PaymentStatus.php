<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '保留中',
            self::Paid => '決済完了',
            self::Failed => '決済失敗',
            self::Canceled => 'キャンセル',
        };
    }
}
