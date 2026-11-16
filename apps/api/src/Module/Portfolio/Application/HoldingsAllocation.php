<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\ExactDecimal;

/**
 * Allocation slices of the holdings report by instrument, asset class, region and account, split from {@see HoldingsAggregator}.
 *
 * @phpstan-import-type Summary from HoldingsAccountSummaries
 */
final class HoldingsAllocation
{
    /**
     * @param list<HoldingRow>                                                                        $items
     * @param list<array{accountId: string, label: string, reason: string, securitiesValue: ?string}> $unsplit
     * @param list<Summary>                                                                           $summaries
     *
     * @return array{byInstrument: list<array{key: string, label: string, value: string, share: ?string}>, byAssetClass: list<array{key: string, label: string, value: string, share: ?string}>, byRegion: list<array{key: string, label: string, value: string, share: ?string}>, byAccount: list<array{key: string, label: string, value: string, share: ?string}>}
     */
    public static function of(array $items, ?string $securities, array $unsplit, array $summaries): array
    {
        if (null === $securities) {
            return ['byInstrument' => [], 'byAssetClass' => [], 'byRegion' => [], 'byAccount' => []];
        }
        $ordered = $items;
        usort($ordered, static fn (HoldingRow $left, HoldingRow $right): int => HoldingsAggregator::compare($left, $right, 'VALUE_DESC'));
        $instrument = [];
        foreach ($ordered as $item) {
            $instrument[] = ['key' => $item->instrument['id'], 'label' => $item->instrument['name'], 'value' => $item->value ?? throw new \LogicException('A known securities value has known lines.')];
        }
        $unsplitSlice = [];
        $unsplitSecurities = HoldingsAggregator::sum(array_map(static fn (array $account): ?string => $account['securitiesValue'], $unsplit));
        if (null !== $unsplitSecurities && ExactDecimal::compareForResponse($unsplitSecurities, '0') > 0) {
            $unsplitSlice = [['key' => 'UNSPLIT', 'label' => 'Comptes valorisés en total', 'value' => $unsplitSecurities]];
        }
        $byAccount = [];
        foreach ($summaries as $summary) {
            if (null !== $summary['securitiesValue'] && ExactDecimal::compareForResponse($summary['securitiesValue'], '0') > 0) {
                $byAccount[] = ['key' => $summary['accountId'], 'label' => $summary['label'], 'value' => $summary['securitiesValue']];
            }
        }

        return ['byInstrument' => HoldingsShares::slices([...$instrument, ...$unsplitSlice], $securities),
            'byAssetClass' => HoldingsShares::slices([...self::group($ordered, static fn (HoldingRow $item): ?string => $item->assetClass), ...$unsplitSlice], $securities),
            'byRegion' => HoldingsShares::slices([...self::group($ordered, static fn (HoldingRow $item): ?string => $item->region), ...$unsplitSlice], $securities),
            'byAccount' => HoldingsShares::slices($byAccount, $securities)];
    }

    /**
     * @param list<HoldingRow>              $items
     * @param \Closure(HoldingRow): ?string $by
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    private static function group(array $items, \Closure $by): array
    {
        $buckets = [];
        foreach ($items as $item) {
            $key = $by($item) ?? 'UNSPECIFIED';
            $buckets[$key] = ['key' => $key, 'label' => 'UNSPECIFIED' === $key ? 'Non renseignée' : $key,
                'value' => ExactDecimal::addForResponse($buckets[$key]['value'] ?? '0', $item->value ?? throw new \LogicException('A known securities value has known lines.'))];
        }
        $groups = array_values($buckets);
        usort($groups, static fn (array $left, array $right): int => ExactDecimal::compareForResponse($right['value'], $left['value']) ?: strcmp($left['key'], $right['key']));

        return $groups;
    }
}
