<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\Positions\Position;
use App\Module\Portfolio\Domain\Positions\PositionLedgerResult;

final class PresentPortfolioPositions
{
    /** @return array<string, mixed> */
    public function position(Position $position, Instrument $instrument, string $assetCode): array
    {
        $amount = static fn (?CalculatedDecimal $value): ?array => null === $value ? null : ['value' => $value->toString(), 'assetCode' => $assetCode];

        return [
            'instrument' => self::instrument($instrument),
            'quantity' => $position->quantity->toString(),
            'fifoCostBasis' => $amount($position->fifoCostBasis),
            'averageCostBasis' => $amount($position->averageCostBasis),
            'averageUnitCost' => $amount($position->averageUnitCost),
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
