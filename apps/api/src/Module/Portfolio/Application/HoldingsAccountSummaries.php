<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Portfolio\Domain\Valuation\PositionSnapshot;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use App\Module\Portfolio\Domain\Valuation\ValuationDetail;

/**
 * The "per account" reading of the holdings: one summary per account from its retained valuation, so an account
 * valued in total still shows its value. A LINES account sums its lines, hence the page never disagrees with itself.
 */
final class HoldingsAccountSummaries
{
    /**
     * @param list<HoldingsAccount>                                                                   $accounts
     * @param list<array{accountId: string, label: string, reason: string, securitiesValue: ?string}> $unsplit
     *
     * @return list<array{accountId: string, label: string, detail: string, valuedOn: ?string, securitiesValue: ?string, costBasis: ?string, unrealizedGain: ?string, performance: ?string, cash: ?string, share: array{ratio: ?string, percentDisplay: ?string, reason: ?string}, lines: list<array<string, mixed>>}>
     */
    public static function of(array $accounts, ?string $itemsSecurities, array $unsplit, bool $mixed): array
    {
        $denominator = $itemsSecurities;
        foreach ($unsplit as $account) {
            $denominator = null === $denominator || null === $account['securitiesValue'] ? null : ExactDecimal::addForResponse($denominator, $account['securitiesValue']);
        }
        $summaries = array_map(static fn (HoldingsAccount $account): array => self::summary($account, $mixed), $accounts);
        $summaries = array_map(static fn (array $summary): array => [...$summary, 'share' => HoldingsShares::of($summary['securitiesValue'], $denominator, $mixed)], $summaries);
        usort($summaries, static fn (array $left, array $right): int => HoldingsAggregator::nullsLast($left['securitiesValue'], $right['securitiesValue'])
            ?: HoldingsAggregator::labelKey($left['label']) <=> HoldingsAggregator::labelKey($right['label']) ?: strcmp($left['accountId'], $right['accountId']));

        return $summaries;
    }

    /** @return array{accountId: string, label: string, detail: string, valuedOn: ?string, securitiesValue: ?string, costBasis: ?string, unrealizedGain: ?string, performance: ?string, cash: ?string, share: array{ratio: ?string, percentDisplay: ?string, reason: ?string}, lines: list<array<string, mixed>>} */
    private static function summary(HoldingsAccount $account, bool $mixed): array
    {
        $valuation = $account->view?->valuation;
        $base = ['accountId' => $account->accountId, 'label' => $account->label, 'detail' => 'NONE', 'valuedOn' => null, 'securitiesValue' => null,
            'costBasis' => null, 'unrealizedGain' => null, 'performance' => null, 'cash' => null, 'share' => HoldingsShares::of(null, null, $mixed), 'lines' => []];
        if (null === $account->view || null === $valuation) {
            return $base;
        }
        $base['valuedOn'] = $valuation->valuedOn->format('Y-m-d');
        $base['cash'] = $mixed ? null : $valuation->cash->toString();
        $lines = [];
        if (ValuationCompleteness::PARTIAL === $valuation->completeness) {
            $base['detail'] = 'PARTIAL';
            $securities = $valuation->securitiesValue?->toString();
            $cost = null;
        } elseif (ValuationDetail::TOTAL === $valuation->detail) {
            $base['detail'] = 'TOTAL';
            $securities = $valuation->securitiesValue?->toString();
            // A statement total is not scoped like positions: no cost, hence no gain.
            $cost = null;
        } else {
            $base['detail'] = 'LINES';
            foreach ($valuation->positions as $position) {
                if (!ExactDecimal::isZero($position->quantity)) {
                    $lines[] = self::line($account, $position, $mixed);
                }
            }
            $securities = HoldingsAggregator::sum(array_map(static fn (PositionSnapshot $position): ?string => ExactDecimal::isZero($position->quantity) ? '0' : $position->marketValue?->toString(), $valuation->positions));
            $cost = HoldingsAggregator::sum(array_map(static fn (PositionSnapshot $position): ?string => ExactDecimal::isZero($position->quantity) ? '0' : $position->averageCostBasis?->toString(), $valuation->positions));
            usort($lines, static fn (array $left, array $right): int => HoldingsAggregator::nullsLast($left['value'], $right['value'])
                ?: HoldingsAggregator::labelKey($left['instrument']['name']) <=> HoldingsAggregator::labelKey($right['instrument']['name']) ?: strcmp($left['instrument']['id'], $right['instrument']['id']));
        }
        if ($mixed) {
            return [...$base, 'lines' => $lines];
        }
        $gain = null !== $securities && null !== $cost ? ExactDecimal::subtractForResponse($securities, $cost) : null;

        return [...$base, 'securitiesValue' => $securities, 'costBasis' => $cost, 'unrealizedGain' => $gain,
            'performance' => PortfolioUnrealizedGain::performance($gain, $cost), 'lines' => $lines];
    }

    /** @return array{instrument: array{id: string, name: string, isin: ?string, ticker: ?string, status: string, quantityPrecision: int}, instrumentType: string, quantity: string, value: ?string, costBasis: ?string, unrealizedGain: ?string, performance: ?string, priceDate: ?string, priceQuality: string} */
    private static function line(HoldingsAccount $account, PositionSnapshot $position, bool $mixed): array
    {
        $instrument = $account->view?->instruments[$position->instrumentId] ?? throw new \LogicException('A frozen instrument is missing.');
        $value = $mixed ? null : $position->marketValue?->toString();
        $cost = $mixed ? null : $position->averageCostBasis?->toString();
        $gain = null !== $value && null !== $cost ? ExactDecimal::subtractForResponse($value, $cost) : null;

        return ['instrument' => ['id' => $instrument->id, 'name' => $instrument->name, 'isin' => $instrument->isin?->toString(),
            'ticker' => $instrument->ticker?->toString(), 'status' => $instrument->status->value, 'quantityPrecision' => $instrument->quantityPrecision],
            'instrumentType' => $instrument->instrumentType->value, 'quantity' => $position->quantity->toString(), 'value' => $value, 'costBasis' => $cost,
            'unrealizedGain' => $gain, 'performance' => PortfolioUnrealizedGain::performance($gain, $cost),
            'priceDate' => $position->priceDate?->format('Y-m-d'), 'priceQuality' => $position->priceQuality->value];
    }
}
