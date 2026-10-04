<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\RoundingMode;

/** The base-100 curve of chained monthly returns: 100 x product(1 + R_m), rounded only for display. */
final class ChainedIndex
{
    private const int SCALE = 24;
    private const int DISPLAY_SCALE = 2;

    /**
     * @param list<?string> $rates      the monthly returns, one per month in order, null where a month has none
     * @param int           $firstIndex the position of the first month of the chain; earlier months have no index
     *
     * @return list<?string> the index at each month end; null before the chain starts and from the first missing return on
     */
    public static function series(array $rates, int $firstIndex): array
    {
        $hundred = CalculatedDecimal::fromDecimal(DecimalValue::fromString('100'));
        $index = $hundred;
        $broken = false;
        $series = [];
        foreach ($rates as $position => $rate) {
            if ($position < $firstIndex || $broken || null === $rate) {
                $broken = $broken || ($position >= $firstIndex && null === $rate);
                $series[] = null;

                continue;
            }
            $factor = CalculatedDecimal::one()->add(CalculatedDecimal::fromDecimal(DecimalValue::fromString($rate)));
            $index = $index->proportion($factor, CalculatedDecimal::one(), self::SCALE, RoundingMode::HALF_EVEN);
            $series[] = $index->round(self::DISPLAY_SCALE, RoundingMode::HALF_UP)->toString();
        }

        return $series;
    }
}
