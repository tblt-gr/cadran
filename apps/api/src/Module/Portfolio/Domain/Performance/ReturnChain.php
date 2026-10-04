<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\RoundingMode;

/** Geometric chaining: product of (1 + R) - 1. Percentages are never summed or averaged. */
final class ReturnChain
{
    public const int MAX_PERIODS = 600;
    public const int MAX_SUBPERIODS = 2000;
    private const int SCALE = 24;

    /**
     * @param list<CalculatedDecimal> $returns unrounded period returns, oldest first
     *
     * @throws ReturnChainTooLong when more than the supplied bounded limit are chained (600 monthly periods by default)
     */
    public static function chain(array $returns, int $limit = self::MAX_PERIODS): CalculatedDecimal
    {
        if ($limit < 1 || $limit > self::MAX_SUBPERIODS) {
            throw new \InvalidArgumentException('The chain limit must be between 1 and '.self::MAX_SUBPERIODS.'.');
        }
        if (count($returns) > $limit) {
            throw new ReturnChainTooLong('A return chain holds at most '.$limit.' periods.');
        }
        $growth = CalculatedDecimal::one();
        foreach ($returns as $return) {
            $factor = CalculatedDecimal::one()->add($return);
            $growth = $growth->proportion($factor, CalculatedDecimal::one(), self::SCALE, RoundingMode::HALF_EVEN);
        }

        return $growth->subtract(CalculatedDecimal::one());
    }
}
