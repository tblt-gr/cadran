<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\Overview\OverviewFigures;
use App\Module\Portfolio\Domain\Valuation\PositionSnapshot;

final readonly class ReadPortfolioAllocation
{
    public function __construct(private PortfolioOverviewQuery $query)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(string $accountId, ?string $asOfLiteral, ?string $byLiteral): array
    {
        [$account, $asOf, $view, $newerPartial] = ($this->query)($accountId, $asOfLiteral);
        $by = $byLiteral ?? 'INSTRUMENT';
        if (!in_array($by, ['INSTRUMENT', 'ASSET_CLASS', 'REGION', 'CURRENCY'], true)) {
            throw new InvalidPortfolioOperation('The allocation grouping is invalid.', 'invalid_allocation_by');
        }
        $valuation = $view?->valuation;
        $reason = match (true) {
            null === $valuation => 'NO_VALUATION',
            'PARTIAL' === $valuation->completeness->value => 'PARTIAL_VALUATION',
            'TOTAL' === $valuation->detail->value => 'NOT_DETAILED',
            null === $valuation->totalValue || $valuation->totalValue->compareTo(DecimalValue::zero()) <= 0 => 'NON_POSITIVE_TOTAL',
            default => null,
        };
        $items = [];
        $slices = [];
        $basis = null;
        $total = $valuation?->totalValue;
        if (in_array($reason, [null, 'NON_POSITIVE_TOTAL'], true) && null !== $view && null !== $total) {
            $basis = 'MARKET';
            [$items, $slices] = self::split($view, $by, $total,
                static fn (PositionSnapshot $position): string => $position->marketValue?->toString() ?? throw new \LogicException('A complete detailed valuation has a market value.'));
        } elseif ('NOT_DETAILED' === $reason && self::costsKnown($view)) {
            // A total-only statement has no market value per line: the exact cost of the frozen
            // lines is the only per-line figure, so the split is shown at cost, never estimated.
            $basis = 'COST';
            $total = $valuation->cash;
            foreach ($valuation->positions as $position) {
                $total = ExactDecimal::add($total, $position->averageCostBasis ?? throw new \LogicException('Costs are known here.'));
            }
            [$items, $slices] = self::split($view, $by, $total,
                static fn (PositionSnapshot $position): string => $position->averageCostBasis?->toString() ?? throw new \LogicException('Costs are known here.'));
        } elseif ('NOT_DETAILED' === $reason) {
            $items = [['key' => 'SECURITIES', 'label' => 'Titres', 'value' => $valuation->securitiesValue?->toString(), 'share' => null],
                ['key' => 'CASH', 'label' => 'Espèces', 'value' => $valuation->cash->toString(), 'share' => null]];
        }

        return ['asOf' => $asOf->format('Y-m-d'), 'by' => $by, 'assetCode' => $account->assetCode->toString(),
            'valuation' => PortfolioOverviewQuery::valuation($view, $asOf),
            'newerPartialValuation' => PortfolioOverviewQuery::valuation($newerPartial, $asOf), 'total' => $total?->toString(),
            'items' => $items, 'slices' => $slices, 'reason' => $reason, 'basis' => $basis];
    }

    private static function costsKnown(ValuationView $view): bool
    {
        $positions = $view->valuation->positions;

        return [] !== $positions && [] === array_filter($positions, static fn (PositionSnapshot $position): bool => null === $position->averageCostBasis);
    }

    /**
     * Groups the frozen lines by instrument, class, region or currency, adds the cash line and
     * divides by `$total`, which is the sum of the grouped values and the cash.
     *
     * @param \Closure(PositionSnapshot): string $valueOf
     *
     * @return array{0: list<array{key: string, label: string, value: string, share: ?string}>, 1: list<array{key: string, label: string, value: string, share: ?string}>}
     */
    private static function split(ValuationView $view, string $by, DecimalValue $total, \Closure $valueOf): array
    {
        $buckets = [];
        foreach ($view->valuation->positions as $position) {
            $instrument = $view->instruments[$position->instrumentId] ?? throw new \LogicException('A frozen instrument is missing.');
            $key = match ($by) {
                'ASSET_CLASS' => null === $instrument->assetClass ? 'UNSPECIFIED' : $instrument->assetClass->value,
                'REGION' => null === $instrument->region ? 'UNSPECIFIED' : $instrument->region->value,
                'CURRENCY' => $instrument->currencyCode->toString(),
                default => $instrument->id,
            };
            $label = 'INSTRUMENT' === $by ? $instrument->name : ('UNSPECIFIED' === $key ? 'Non renseignée' : $key);
            $previous = $buckets[$key]['value'] ?? '0';
            $buckets[$key] = ['key' => $key, 'label' => $label, 'value' => ExactDecimal::addForResponse($previous, $valueOf($position))];
        }
        uasort($buckets, static fn (array $left, array $right): int => strcasecmp($left['label'], $right['label']));
        $buckets['CASH'] = ['key' => 'CASH', 'label' => 'Espèces', 'value' => $view->valuation->cash->toString()];
        $figures = OverviewFigures::allocation(array_values($buckets), $total);

        return [$figures['items'], $figures['slices']];
    }
}
