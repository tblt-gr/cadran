<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\RoundingMode;

/** Geometric chaining: product of (1 + R) - 1. Percentages are never summed or averaged. */
final class ReturnChain
{
    public const int MAX_PERIODS = 600;
    private const int SCALE = 24;

    /**
     * @param list<CalculatedDecimal> $returns unrounded period returns, oldest first
     *
     * @throws ReturnChainTooLong when more than MAX_PERIODS returns are chained
     */
    public static function chain(array $returns): CalculatedDecimal
    {
        if (count($returns) > self::MAX_PERIODS) {
            throw new ReturnChainTooLong('A return chain holds at most '.self::MAX_PERIODS.' periods.');
        }
        $growth = CalculatedDecimal::one();
        foreach ($returns as $return) {
            $factor = CalculatedDecimal::one()->add($return);
            $growth = $growth->proportion($factor, CalculatedDecimal::one(), self::SCALE, RoundingMode::HALF_EVEN);
        }

        return $growth->subtract(CalculatedDecimal::one());
    }
}
