<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

enum PerformanceQuality: string
{
    case EXACT = 'EXACT';
    case APPROXIMATION = 'APPROXIMATION';
    case DEGRADED = 'DEGRADED';
    case NOT_CALCULABLE = 'NOT_CALCULABLE';

    /** The method level lowered by the warnings, and fixed to NOT_CALCULABLE by any blocking reason. */
    public static function of(self $method, bool $hasBlocking, bool $hasWarning): self
    {
        if ($hasBlocking) {
            return self::NOT_CALCULABLE;
        }

        return $hasWarning ? self::worst($method, self::DEGRADED) : $method;
    }

    public static function worst(self $left, self $right): self
    {
        return self::rank($left) >= self::rank($right) ? $left : $right;
    }

    private static function rank(self $quality): int
    {
        return (int) array_search($quality, self::cases(), true);
    }
}
