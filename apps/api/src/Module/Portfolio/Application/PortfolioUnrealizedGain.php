<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Portfolio\Domain\Overview\OverviewFigures;

/** Unrealized gain of a valuation: securities value minus the acquisition cost of the open lots. */
final class PortfolioUnrealizedGain
{
    /** Decimal places of the performance ratio (0.097561 is 9.7561 %), rounded half away from zero. */
    public const int PERFORMANCE_SCALE = 6;

    /** @return array{value: ?string, securitiesValue: ?string, costBasis: ?string, reason: ?string} */
    public static function of(?ValuationView $view): array
    {
        $valuation = $view?->valuation;
        $securities = $valuation?->securitiesValue?->toString();
        $reason = match (true) {
            null === $valuation => 'NO_VALUATION',
            'PARTIAL' === $valuation->completeness->value => 'PARTIAL_VALUATION',
            default => null,
        };
        $costs = array_map(static fn ($position): ?string => $position->averageCostBasis?->toString(), null === $valuation ? [] : $valuation->positions);
        $value = null === $reason && null !== $securities ? OverviewFigures::gain($securities, $costs) : null;
        if (null === $value && null === $reason) {
            $reason = 'UNKNOWN_LOT_COST';
        }

        $costBasis = null;
        if (null !== $value) {
            $costBasis = '0';
            foreach ($costs as $cost) {
                $costBasis = ExactDecimal::addForResponse($costBasis, $cost ?? throw new \LogicException('A computed gain has known costs.'));
            }
        }

        return ['value' => $value, 'securitiesValue' => $securities, 'costBasis' => $costBasis, 'reason' => $reason];
    }

    /** Gain over cost as an exact ratio; null when either side is unknown or the cost is zero. */
    public static function performance(?string $gain, ?string $costBasis): ?string
    {
        if (null === $gain || null === $costBasis || ExactDecimal::isZeroForResponse($costBasis)) {
            return null;
        }

        return ExactDecimal::divideForResponse($gain, $costBasis, self::PERFORMANCE_SCALE);
    }
}
