<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\Overview\OverviewFigures;

final readonly class ReadPortfolioAllocation
{
    public function __construct(private PortfolioOverviewQuery $query)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(string $accountId, ?string $asOfLiteral, ?string $byLiteral): array
    {
        [$account, $asOf, $view] = ($this->query)($accountId, $asOfLiteral);
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
        if ('NOT_DETAILED' === $reason) {
            $items = [['key' => 'SECURITIES', 'label' => 'Titres', 'value' => $valuation->securitiesValue?->toString(), 'share' => null],
                ['key' => 'CASH', 'label' => 'Espèces', 'value' => $valuation->cash->toString(), 'share' => null]];
        }
        $slices = [];
        if (in_array($reason, [null, 'NON_POSITIVE_TOTAL'], true) && null !== $valuation && null !== $valuation->totalValue) {
            $buckets = [];
            foreach ($valuation->positions as $position) {
                $instrument = $view->instruments[$position->instrumentId] ?? throw new \LogicException('A frozen instrument is missing.');
                $key = match ($by) {
                    'ASSET_CLASS' => null === $instrument->assetClass ? 'UNSPECIFIED' : $instrument->assetClass->value,
                    'REGION' => null === $instrument->region ? 'UNSPECIFIED' : $instrument->region->value,
                    'CURRENCY' => $instrument->currencyCode->toString(),
                    default => $instrument->id,
                };
                $label = 'INSTRUMENT' === $by ? $instrument->name : ('UNSPECIFIED' === $key ? 'Non renseignée' : $key);
                $previous = $buckets[$key]['value'] ?? '0';
                $buckets[$key] = ['key' => $key, 'label' => $label, 'value' => ExactDecimal::addForResponse($previous, $position->marketValue?->toString() ?? throw new \LogicException('A complete detailed valuation has a market value.'))];
            }
            uasort($buckets, static fn (array $left, array $right): int => strcasecmp($left['label'], $right['label']));
            $buckets['CASH'] = ['key' => 'CASH', 'label' => 'Espèces', 'value' => $valuation->cash->toString()];
            $figures = OverviewFigures::allocation(array_values($buckets), $valuation->totalValue);
            $items = $figures['items'];
            $slices = $figures['slices'];
        }

        return ['asOf' => $asOf->format('Y-m-d'), 'by' => $by, 'assetCode' => $account->assetCode->toString(),
            'valuation' => PortfolioOverviewQuery::valuation($view, $asOf), 'total' => $valuation?->totalValue?->toString(),
            'items' => $items, 'slices' => $slices, 'reason' => $reason];
    }
}
