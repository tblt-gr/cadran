<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

enum PriceQuality: string
{
    case CURRENT = 'CURRENT';
    case STALE = 'STALE';
    case MISSING = 'MISSING';
    case NOT_DETAILED = 'NOT_DETAILED';

    /** @param list<self> $qualities */
    public static function worst(array $qualities): self
    {
        foreach ([self::NOT_DETAILED, self::MISSING, self::STALE] as $level) {
            if (in_array($level, $qualities, true)) {
                return $level;
            }
        }

        return self::CURRENT;
    }
}
