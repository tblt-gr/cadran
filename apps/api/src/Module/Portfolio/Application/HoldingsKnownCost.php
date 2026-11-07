<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\ExactDecimal;

/**
 * Figures over the positions whose cost and value are both known, kept apart from the exact totals: a partial sum is
 * never presented as the total. The caller decides when the full cost is unknown; this only measures what is known.
 *
 * @phpstan-type KnownCost array{securitiesValue: string, costBasis: string, unrealizedGain: string, performance: ?string, excludedCount: int, excludedValue: ?string}
 */
final class HoldingsKnownCost
{
    /**
     * @param list<array{value: ?string, cost: ?string}> $entries one per position, or per block for a total-valued account
     *
     * @return KnownCost|null null when nothing is both costed and valued, or nothing is excluded
     */
    public static function of(array $entries): ?array
    {
        $securities = '0';
        $cost = '0';
        $excluded = [];
        $included = 0;
        foreach ($entries as $entry) {
            if (null === $entry['value'] || null === $entry['cost']) {
                $excluded[] = $entry['value'];
                continue;
            }
            ++$included;
            $securities = ExactDecimal::addForResponse($securities, $entry['value']);
            $cost = ExactDecimal::addForResponse($cost, $entry['cost']);
        }
        if (0 === $included || [] === $excluded) {
            return null;
        }
        $gain = ExactDecimal::subtractForResponse($securities, $cost);

        return ['securitiesValue' => $securities, 'costBasis' => $cost, 'unrealizedGain' => $gain,
            'performance' => PortfolioUnrealizedGain::performance($gain, $cost), 'excludedCount' => count($excluded),
            'excludedValue' => HoldingsAggregator::sum($excluded)];
    }
}
