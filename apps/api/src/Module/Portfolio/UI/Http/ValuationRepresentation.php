<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Application\DraftPosition;
use App\Module\Portfolio\Application\ValuationDraftView;
use App\Module\Portfolio\Application\ValuationHistory;
use App\Module\Portfolio\Application\ValuationPage;
use App\Module\Portfolio\Application\ValuationView;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\Valuation\PositionSnapshot;
use App\Module\Portfolio\Domain\Valuation\ValuationFlag;
use App\Module\Portfolio\Domain\Valuation\ValuationRules;

final class ValuationRepresentation
{
    /** @return array<string, mixed> */
    public static function page(ValuationPage $page): array
    {
        return [
            'items' => array_map(self::summary(...), $page->items),
            'page' => $page->page,
            'perPage' => $page->perPage,
            'total' => $page->total,
        ];
    }

    /** @return array<string, mixed> */
    public static function history(ValuationHistory $history): array
    {
        return [
            'from' => $history->from?->format('Y-m-d'),
            'to' => $history->to->format('Y-m-d'),
            'assetCode' => $history->assetCode,
            'truncated' => $history->truncated,
            'points' => array_map(static function (ValuationView $view): array {
                $valuation = $view->valuation;
                $asset = $valuation->assetCode->toString();

                return [
                    'valuationId' => $valuation->id,
                    'valuedOn' => $valuation->valuedOn->format('Y-m-d'),
                    'detail' => $valuation->detail->value,
                    'totalValue' => self::amount($valuation->totalValue, $asset),
                    'securitiesValue' => self::amount($valuation->securitiesValue, $asset),
                    'cash' => self::amount($valuation->cash, $asset),
                    'completeness' => $valuation->completeness->value,
                    'priceQuality' => $valuation->priceQuality()->value,
                    'flags' => array_map(static fn (ValuationFlag $flag): string => $flag->value, $view->flags),
                ];
            }, $history->points),
        ];
    }

    /** @return array<string, mixed> */
    public static function summary(ValuationView $view): array
    {
        $valuation = $view->valuation;
        $asset = $valuation->assetCode->toString();

        return [
            'id' => $valuation->id,
            'accountId' => $valuation->accountId,
            'valuedOn' => $valuation->valuedOn->format('Y-m-d'),
            'detail' => $valuation->detail->value,
            'source' => $valuation->source->value,
            'totalValue' => self::amount($valuation->totalValue, $asset),
            'cash' => self::amount($valuation->cash, $asset),
            'securitiesValue' => self::amount($valuation->securitiesValue, $asset),
            'completeness' => $valuation->completeness->value,
            'priceQuality' => $valuation->priceQuality()->value,
            'flags' => array_map(static fn (ValuationFlag $flag): string => $flag->value, $view->flags),
            'version' => $valuation->version,
        ];
    }

    /** @return array<string, mixed> */
    public static function one(ValuationView $view): array
    {
        $valuation = $view->valuation;
        $asset = $valuation->assetCode->toString();

        return [
            ...self::summary($view),
            'derivedCash' => self::amount($valuation->derivedCash, $asset),
            'cashSource' => $valuation->cashSource->value,
            'cashDifference' => self::amount($valuation->cashDifference(), $asset),
            'observedTotal' => self::amount($valuation->observedTotal, $asset),
            'observedTotalDifference' => self::amount($valuation->observedTotalDifference(), $asset),
            'tolerance' => self::amount($valuation->tolerance($view->displayPrecision), $asset),
            'note' => $valuation->note,
            'supersedesId' => $valuation->supersedesId,
            'supersededAt' => $valuation->supersededAt?->format(\DATE_ATOM),
            'recordedAt' => $valuation->recordedAt->format(\DATE_ATOM),
            'positions' => array_map(
                static fn (PositionSnapshot $line): array => self::position($line, $view->instruments[$line->instrumentId] ?? null, $valuation->valuedOn, $asset),
                self::byInstrumentName($valuation->positions, $view->instruments),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public static function draft(ValuationDraftView $draft): array
    {
        return [
            'valuedOn' => $draft->valuedOn->format('Y-m-d'),
            'derivedCash' => self::amount($draft->derivedCash, $draft->assetCode),
            'suggestedDetail' => $draft->suggestedDetail->value,
            'lastTotal' => null === $draft->lastTotal || null === $draft->lastTotalOn
                ? null
                : ['value' => self::amount($draft->lastTotal, $draft->assetCode), 'valuedOn' => $draft->lastTotalOn->format('Y-m-d')],
            'currentValuation' => null === $draft->currentValuationId
                ? null
                : ['id' => $draft->currentValuationId, 'version' => $draft->currentValuationVersion],
            'positions' => array_map(static fn (DraftPosition $position): array => [
                'instrument' => self::instrument($position->instrument),
                'quantity' => $position->quantity->toString(),
                'lastPrice' => $position->lastPrice?->toString(),
                'lastPriceDate' => $position->lastPriceDate?->format('Y-m-d'),
                'lastPriceOrigin' => null === $position->originKind || null === $position->lastPriceDate
                    ? null
                    : ['kind' => $position->originKind, 'id' => $position->originId, 'date' => $position->lastPriceDate->format('Y-m-d')],
            ], $draft->positions),
        ];
    }

    /**
     * @param list<PositionSnapshot>    $positions
     * @param array<string, Instrument> $instruments
     *
     * @return list<PositionSnapshot>
     */
    private static function byInstrumentName(array $positions, array $instruments): array
    {
        $name = static fn (PositionSnapshot $line): string => isset($instruments[$line->instrumentId]) ? $instruments[$line->instrumentId]->name : $line->instrumentId;
        usort($positions, static fn (PositionSnapshot $left, PositionSnapshot $right): int => strcasecmp($name($left), $name($right)));

        return $positions;
    }

    /** @return array<string, mixed> */
    private static function position(PositionSnapshot $line, ?Instrument $instrument, \DateTimeImmutable $valuedOn, string $asset): array
    {
        return [
            'instrument' => null === $instrument ? ['id' => $line->instrumentId] : self::instrument($instrument),
            'quantity' => $line->quantity->toString(),
            'unitPrice' => $line->unitPrice?->toString(),
            'priceDate' => $line->priceDate?->format('Y-m-d'),
            'priceAgeDays' => null === $line->priceDate ? null : ValuationRules::ageDays($line->priceDate, $valuedOn),
            'priceQuality' => $line->priceQuality->value,
            'marketValue' => self::amount($line->marketValue, $asset),
            'averageCostBasis' => self::amount($line->averageCostBasis, $asset),
        ];
    }

    /** @return array<string, mixed> */
    private static function instrument(Instrument $instrument): array
    {
        return [
            'id' => $instrument->id,
            'name' => $instrument->name,
            'isin' => $instrument->isin?->toString(),
            'ticker' => $instrument->ticker?->toString(),
            'status' => $instrument->status->value,
            'quantityPrecision' => $instrument->quantityPrecision,
            'pricePrecision' => $instrument->pricePrecision,
        ];
    }

    /** @return array{value: string, assetCode: string}|null */
    private static function amount(?DecimalValue $value, string $assetCode): ?array
    {
        return null === $value ? null : ['value' => $value->toString(), 'assetCode' => $assetCode];
    }
}
