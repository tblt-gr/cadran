<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final class RefundDatePolicy
{
    public static function assertNotBeforeOriginal(\DateTimeImmutable $refundDate, \DateTimeImmutable $originalDate): void
    {
        if ($refundDate < $originalDate) {
            throw new InvalidRefundRule('date_before_original');
        }
    }
}
