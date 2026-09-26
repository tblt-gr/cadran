<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

/** Two periods are comparable only when the same policy version produced both. */
enum MetricPolicyComparability: string
{
    case COMPARABLE = 'COMPARABLE';
    case NOT_COMPARABLE = 'NOT_COMPARABLE';

    public static function compare(int $a, int $b): self
    {
        return $a === $b ? self::COMPARABLE : self::NOT_COMPARABLE;
    }
}
