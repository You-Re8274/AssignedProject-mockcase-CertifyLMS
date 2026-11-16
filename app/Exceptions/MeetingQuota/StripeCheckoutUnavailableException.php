<?php

declare(strict_types=1);

namespace App\Exceptions\MeetingQuota;

use RuntimeException;
use Throwable;

final class StripeCheckoutUnavailableException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Stripe Checkout is unavailable.', 0, $previous);
    }
}
