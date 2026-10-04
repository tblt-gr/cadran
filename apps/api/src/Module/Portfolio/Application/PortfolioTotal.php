<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\ExactDecimal;

/** Aggregates the latest valuations of the shown accounts into one total, one asset at a time. */
final class PortfolioTotal
{
    /**
     * @param list<array{assetCode: string, totalValue: ?string, gain: ?string, costBasis: ?string, valuedOn: ?string, newerPartial: bool}> $accounts
     *
     * @return array{assetCode: ?string, totalValue: ?string, unrealizedGain: ?string, costBasis: ?string, performance: ?string, valuedAccounts: int, unvaluedAccounts: int, oldestValuedOn: ?string, partialNewerAccounts: int, reason: ?string}
     */
    public static function of(array $accounts): array
    {
        $assets = array_values(array_unique(array_column($accounts, 'assetCode')));
        $valued = array_values(array_filter($accounts, static fn (array $account): bool => null !== $account['totalValue']));
        $counts = ['valuedAccounts' => count($valued), 'unvaluedAccounts' => count($accounts) - count($valued),
            'oldestValuedOn' => self::oldest($valued), 'partialNewerAccounts' => count(array_filter($valued, static fn (array $account): bool => $account['newerPartial']))];
        $empty = ['assetCode' => null, 'totalValue' => null, 'unrealizedGain' => null, 'costBasis' => null, 'performance' => null];
        if (count($assets) > 1) {
            return [...$empty, ...$counts, 'oldestValuedOn' => null, 'partialNewerAccounts' => 0, 'reason' => 'MIXED_ASSETS'];
        }
        $empty['assetCode'] = $assets[0] ?? null;
        if ([] === $valued) {
            return [...$empty, ...$counts, 'reason' => 'NO_VALUATION'];
        }

        $total = '0';
        $gain = '0';
        $cost = '0';
        $complete = true;
        foreach ($valued as $account) {
            $total = ExactDecimal::addForResponse($total, $account['totalValue']);
            if (null === $account['gain'] || null === $account['costBasis']) {
                $complete = false;
                continue;
            }
            $gain = ExactDecimal::addForResponse($gain, $account['gain']);
            $cost = ExactDecimal::addForResponse($cost, $account['costBasis']);
        }
        $figures = $complete
            ? ['unrealizedGain' => $gain, 'costBasis' => $cost, 'performance' => PortfolioUnrealizedGain::performance($gain, $cost)]
            : ['unrealizedGain' => null, 'costBasis' => null, 'performance' => null];

        return [...$empty, 'totalValue' => $total, ...$figures, ...$counts, 'reason' => $complete ? null : 'INCOMPLETE_GAIN'];
    }

    /** @param list<array{valuedOn: ?string}> $valued ISO dates sort chronologically as strings. */
    private static function oldest(array $valued): ?string
    {
        $dates = array_filter(array_column($valued, 'valuedOn'), static fn (?string $date): bool => null !== $date);

        return [] === $dates ? null : min($dates);
    }
}
