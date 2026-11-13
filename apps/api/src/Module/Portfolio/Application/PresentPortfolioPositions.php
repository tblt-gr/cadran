<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\Positions\Position;
use App\Module\Portfolio\Domain\Positions\PositionLedgerResult;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use App\Module\Portfolio\Domain\Valuation\ValuationDetail;

final class PresentPortfolioPositions
{
    /** @return array<string, mixed> */
    public function position(Position $position, Instrument $instrument, string $assetCode, ?ValuationView $retained = null): array
    {
        $amount = static fn (?CalculatedDecimal $value): ?array => null === $value ? null : ['value' => $value->toString(), 'assetCode' => $assetCode];

        return [
            'instrument' => self::instrument($instrument),
            'quantity' => $position->quantity->toString(),
            'fifoCostBasis' => $amount($position->fifoCostBasis),
            'averageCostBasis' => $amount($position->averageCostBasis),
            'averageUnitCost' => $amount($position->averageUnitCost),
            ...self::value($position, $instrument, $assetCode, $retained),
            'costReason' => $position->costReason,
            'firstAcquiredOn' => $position->firstAcquiredOn?->format('Y-m-d'),
            'lastOperationOn' => $position->lastOperationOn?->format('Y-m-d'),
            'openLotCount' => count($position->openLots),
            'flags' => [] === $position->orderDependentDates ? [] : [[
                'code' => 'ORDER_DEPENDENT_DAY',
                'dates' => $position->orderDependentDates,
            ]],
        ];
    }

    /**
     * Value and gain come from the frozen line of the retained valuation, never from a rescaled price:
     * they exist only while the replayed quantity still equals the quantity that was frozen.
     *
     * @return array{marketValue: ?array{value: string, assetCode: string}, unrealizedGain: ?array{value: string, assetCode: string}, valueReason: ?string}
     */
    private static function value(Position $position, Instrument $instrument, string $assetCode, ?ValuationView $retained): array
    {
        $none = static fn (string $reason): array => ['marketValue' => null, 'unrealizedGain' => null, 'valueReason' => $reason];
        $valuation = $retained?->valuation;
        if (null === $valuation) {
            return $none('NO_VALUATION');
        }
        if (ValuationCompleteness::PARTIAL === $valuation->completeness) {
            return $none('PARTIAL_VALUATION');
        }
        if (ValuationDetail::TOTAL === $valuation->detail) {
            return $none('NOT_DETAILED');
        }
        foreach ($valuation->positions as $line) {
            if ($line->instrumentId !== $instrument->id) {
                continue;
            }
            if (null === $line->marketValue || 0 !== CalculatedDecimal::fromDecimal($line->quantity)->compareTo($position->quantity)) {
                return $none('QUANTITY_CHANGED');
            }
            $gain = null === $line->averageCostBasis ? null : ExactDecimal::subtractForResponse($line->marketValue->toString(), $line->averageCostBasis->toString());

            return [
                'marketValue' => ['value' => $line->marketValue->toString(), 'assetCode' => $assetCode],
                'unrealizedGain' => null === $gain ? null : ['value' => $gain, 'assetCode' => $assetCode],
                'valueReason' => null,
            ];
        }

        return $none('QUANTITY_CHANGED');
    }

    public function lots(PositionLedgerResult $result, ?Position $position, Instrument $instrument, string $assetCode, string $asOf): PortfolioLotsView
    {
        $openLots = [];
        foreach (null === $position ? [] : $position->openLots as $lot) {
            $unitCost = null === $lot->costBasis || $lot->openQuantity->isZero()
                ? null
                : $lot->costBasis->proportion(CalculatedDecimal::one(), $lot->openQuantity, DecimalValue::MAX_SCALE, RoundingMode::HALF_EVEN);
            $openLots[] = [
                'lotId' => $lot->lotId,
                'originOperationId' => $lot->originOperationId,
                'originType' => $lot->originType->value,
                'acquiredOn' => $lot->acquiredOn->format('Y-m-d'),
                'originalQuantity' => $lot->originalQuantity->toString(),
                'openQuantity' => $lot->openQuantity->toString(),
                'costBasis' => self::amount($lot->costBasis, $assetCode),
                'unitCost' => self::amount($unitCost, $assetCode),
            ];
        }
        $movements = [];
        foreach ($result->movements[$instrument->id] ?? [] as $movement) {
            $movements[] = [
                'operationId' => $movement->operationId,
                'tradeDate' => $movement->tradeDate->format('Y-m-d'),
                'sequence' => $movement->sequence,
                'type' => $movement->type->value,
                'quantityDelta' => $movement->quantityDelta->toString(),
                'costDelta' => self::amount($movement->costDelta, $assetCode),
                'lotId' => $movement->lotId,
            ];
        }

        return new PortfolioLotsView(
            $asOf,
            self::instrument($instrument),
            $openLots,
            $movements,
            self::amount($position?->fifoCostBasis, $assetCode),
        );
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
        ];
    }

    /** @return array{value: string, assetCode: string}|null */
    private static function amount(?CalculatedDecimal $value, string $assetCode): ?array
    {
        return null === $value ? null : ['value' => $value->toString(), 'assetCode' => $assetCode];
    }
}
