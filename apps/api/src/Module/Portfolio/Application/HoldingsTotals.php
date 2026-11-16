<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\ExactDecimal;

/**
 * Portfolio-level totals of the holdings report, split from {@see HoldingsAggregator}.
 *
 * @phpstan-import-type Summary from HoldingsAccountSummaries
 */
final class HoldingsTotals
{
    /**
     * @param list<HoldingRow>                                                                        $items
     * @param list<array{accountId: string, label: string, reason: string, securitiesValue: ?string}> $unsplit
     * @param list<Summary>                                                                           $figures
     *
     * @return array{securitiesValue: ?string, costBasis: ?string, unrealizedGain: ?string, performance: ?string, cash: ?string, totalValue: ?string, reason: ?string, knownCost: ?array<string, mixed>}
     */
    public static function of(array $items, array $unsplit, array $figures, string $cash, bool $cashMissing, bool $mixed, bool $noAccount): array
    {
        if ($mixed || $noAccount) {
            return ['securitiesValue' => null, 'costBasis' => null, 'unrealizedGain' => null, 'performance' => null, 'cash' => null,
                'totalValue' => null, 'reason' => $mixed ? 'MIXED_ASSETS' : 'NO_VALUATION', 'knownCost' => null];
        }
        $costs = [];
        foreach ($figures as $figure) {
            $costs[$figure['accountId']] = $figure['costBasis'];
        }
        $itemsSecurities = HoldingsAggregator::sum(array_map(static fn (HoldingRow $item): ?string => $item->value, $items));
        $unsplitSecurities = HoldingsAggregator::sum(array_map(static fn (array $account): ?string => $account['securitiesValue'], $unsplit));
        // The frozen cost of a total-valued account is the one the accounts overview exposes; unknown stays unknown.
        $cost = HoldingsAggregator::sum([...array_map(static fn (HoldingRow $item): ?string => $item->costBasis, $items),
            ...array_map(static fn (array $account): ?string => $costs[$account['accountId']] ?? null, $unsplit)]);
        $securities = null === $itemsSecurities || null === $unsplitSecurities ? null : ExactDecimal::addForResponse($itemsSecurities, $unsplitSecurities);
        $gain = null !== $securities && null !== $cost ? ExactDecimal::subtractForResponse($securities, $cost) : null;
        $total = null === $securities || $cashMissing ? null : ExactDecimal::addForResponse($securities, $cash);
        $reason = match (true) {
            null === $itemsSecurities => 'MISSING_PRICE',
            null === $unsplitSecurities => 'NO_VALUATION',
            null === $cost => 'UNKNOWN_LOT_COST',
            $cashMissing => 'NO_VALUATION',
            default => null,
        };

        $known = null;
        if ('UNKNOWN_LOT_COST' === $reason) {
            $entries = [];
            foreach ($figures as $figure) {
                if ('LINES' === $figure['detail']) {
                    array_push($entries, ...array_map(static fn (array $line): array => ['value' => $line['value'], 'cost' => $line['costBasis']], $figure['lines']));
                } elseif ('TOTAL' === $figure['detail']) {
                    $entries[] = ['value' => $figure['securitiesValue'], 'cost' => $figure['costBasis']];
                } elseif ('PARTIAL' === $figure['detail'] && null !== $figure['securitiesValue']) {
                    // Counted in the securities total, but nothing says what it cost: one excluded block.
                    $entries[] = ['value' => $figure['securitiesValue'], 'cost' => null];
                }
            }
            $known = HoldingsKnownCost::of($entries);
        }

        return ['securitiesValue' => $securities, 'costBasis' => $cost, 'unrealizedGain' => $gain,
            'performance' => PortfolioUnrealizedGain::performance($gain, $cost), 'cash' => $cashMissing ? null : $cash,
            'totalValue' => $total, 'reason' => $reason, 'knownCost' => $known];
    }
}
