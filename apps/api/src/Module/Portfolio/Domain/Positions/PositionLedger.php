<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Positions;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Portfolio\Domain\LegDirection;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;

/** Pure replay of active operations. No result produced here is persisted. */
final class PositionLedger
{
    private const string UNKNOWN_COST = 'UNKNOWN_LOT_COST';

    public function __construct(private readonly CorporateActionLots $corporateActions = new CorporateActionLots())
    {
    }

    /**
     * @param list<PortfolioOperation> $operations
     * @param array<string, int>       $quantityPrecisions instrument id => decimal places
     */
    public function replay(array $operations, array $quantityPrecisions): PositionLedgerResult
    {
        usort($operations, self::compareOperations(...));
        /** @var array<string, LedgerState> $states */
        $states = [];
        /** @var array<string, list<LotMovement>> $movements */
        $movements = [];
        $directions = $this->corporateActions->directions($operations);

        foreach ($operations as $operation) {
            $this->apply($states, $movements, $operation, $quantityPrecisions);
        }

        $positions = [];
        $released = [];
        foreach ($states as $instrumentId => $state) {
            $released += $state->released;
            $quantity = $state->poolQuantity;
            $fifoCost = LedgerValues::lotCost($state->lots);
            $averageCost = $state->poolCost;
            $averageUnit = null === $averageCost || $quantity->isZero()
                ? null
                : $averageCost->proportion(CalculatedDecimal::one(), $quantity, DecimalValue::MAX_SCALE, RoundingMode::HALF_EVEN);
            $positions[$instrumentId] = new Position(
                $instrumentId,
                $quantity,
                $fifoCost,
                $averageCost,
                $averageUnit,
                (null === $fifoCost || null === $averageCost) && !$quantity->isZero() ? self::UNKNOWN_COST : null,
                $state->first,
                $state->last,
                $state->lots,
                self::orderDependentDates($directions[$instrumentId] ?? []),
            );
        }

        return new PositionLedgerResult($positions, $movements, $released);
    }

    /**
     * The non-zero quantities held at the end of each given day, from one pass over the operations in date order.
     * A day is null from the first operation that cannot be replayed.
     *
     * @param list<PortfolioOperation> $operations
     * @param array<string, int>       $quantityPrecisions instrument id => decimal places
     * @param list<\DateTimeImmutable> $days
     *
     * @return array<string, array<string, CalculatedDecimal>|null> keyed by `Y-m-d`
     */
    public function quantitiesAt(array $operations, array $quantityPrecisions, array $days): array
    {
        usort($operations, self::compareOperations(...));
        $pending = array_values(array_unique(array_map(static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'), $days)));
        sort($pending);
        /** @var array<string, LedgerState> $states */
        $states = [];
        /** @var array<string, list<LotMovement>> $movements */
        $movements = [];
        $result = [];
        $held = static function () use (&$states): array {
            $quantities = [];
            foreach ($states as $instrumentId => $state) {
                if (!$state->poolQuantity->isZero()) {
                    $quantities[$instrumentId] = $state->poolQuantity;
                }
            }

            return $quantities;
        };

        try {
            foreach ($operations as $operation) {
                $tradeDay = $operation->details->tradeDate->format('Y-m-d');
                while ([] !== $pending && $pending[0] < $tradeDay) {
                    $result[array_shift($pending)] = $held();
                }
                $this->apply($states, $movements, $operation, $quantityPrecisions);
            }
            foreach ($pending as $day) {
                $result[$day] = $held();
            }
        } catch (PositionReplayFailed) {
            foreach ($pending as $day) {
                $result[$day] = null;
            }
        }

        return $result;
    }

    /**
     * @param array<string, LedgerState>       $states
     * @param array<string, list<LotMovement>> $movements
     * @param array<string, int>               $quantityPrecisions
     */
    private function apply(array &$states, array &$movements, PortfolioOperation $operation, array $quantityPrecisions): void
    {
        if (!$operation->isActive()) {
            return;
        }
        $details = $operation->details;
        foreach ([$details->instrumentId, $details->counterInstrumentId] as $touchedId) {
            if (null !== $touchedId) {
                $states[$touchedId] ??= new LedgerState();
                $states[$touchedId]->last = $details->tradeDate;
            }
        }

        $legs = $details->legs;
        usort($legs, static fn ($a, $b): int => [LegDirection::OUT === $a->direction ? 0 : 1, $a->index] <=> [LegDirection::OUT === $b->direction ? 0 : 1, $b->index]);
        foreach ($legs as $leg) {
            $states[$leg->instrumentId] ??= new LedgerState();
            $states[$leg->instrumentId]->last = $details->tradeDate;
            if (LegDirection::OUT === $leg->direction) {
                $this->dispose($states, $movements, $operation, $leg->instrumentId, LedgerValues::calculated($leg->quantity), $leg->index);
            } else {
                $this->acquire($states, $movements, $operation, $leg->instrumentId, LedgerValues::calculated($leg->quantity), LedgerValues::calculated($leg->amount), $leg->index);
            }
        }

        if ($details->type->isCreditedInterest()) {
            $net = LedgerValues::calculated(LedgerValues::requiredDecimal($details->netCreditedAmount()));
            $this->acquire($states, $movements, $operation, LedgerValues::required($details->instrumentId), LedgerValues::derivedQuantity(LedgerValues::requiredDecimal($details->netCreditedAmount())), $net);
        } elseif (null !== $details->supportDeduction()) {
            $this->dispose($states, $movements, $operation, LedgerValues::required($details->instrumentId), LedgerValues::derivedQuantity($details->supportDeduction()));
        }
        match ($details->type) {
            OperationType::BUY => $this->acquire(
                $states, $movements, $operation, LedgerValues::required($details->instrumentId),
                LedgerValues::calculated(LedgerValues::requiredDecimal($details->quantity)),
                LedgerValues::acquisitionCost($details->grossAmount, $details->fees, $details->taxes),
            ),
            OperationType::SECURITY_TRANSFER_IN => $this->acquire($states, $movements, $operation, LedgerValues::required($details->instrumentId), LedgerValues::calculated(LedgerValues::requiredDecimal($details->quantity)), LedgerValues::nullableCalculated($details->costBasis)),
            OperationType::SELL, OperationType::SECURITY_TRANSFER_OUT => $this->dispose($states, $movements, $operation, LedgerValues::required($details->instrumentId), LedgerValues::calculated(LedgerValues::requiredDecimal($details->quantity))),
            OperationType::SPLIT => $this->corporateActions->split($states, $movements, $operation, LedgerValues::required($details->instrumentId), $quantityPrecisions[LedgerValues::required($details->instrumentId)] ?? 0),
            OperationType::MERGER => $this->corporateActions->merge(
                $states, $movements, $operation, LedgerValues::required($details->instrumentId),
                LedgerValues::required($details->counterInstrumentId),
                LedgerValues::calculated(LedgerValues::requiredDecimal($details->counterQuantity)),
                $quantityPrecisions[LedgerValues::required($details->counterInstrumentId)] ?? 0,
            ),
            OperationType::SPIN_OFF => $this->corporateActions->spinOff(
                $states, $movements, $operation, LedgerValues::required($details->instrumentId),
                LedgerValues::required($details->counterInstrumentId),
                LedgerValues::calculated(LedgerValues::requiredDecimal($details->counterQuantity)),
                LedgerValues::calculated(LedgerValues::requiredDecimal($details->costAllocationRatio)),
                $quantityPrecisions[LedgerValues::required($details->counterInstrumentId)] ?? 0,
            ),
            default => null,
        };
    }

    /**
     * @param array<string, LedgerState>       $states
     * @param array<string, list<LotMovement>> $movements
     */
    private function acquire(array &$states, array &$movements, PortfolioOperation $operation, string $instrumentId, CalculatedDecimal $quantity, ?CalculatedDecimal $cost, int $legIndex = 0): void
    {
        $state = $states[$instrumentId];
        $lot = new OpenLot(
            $operation->id.'#'.$legIndex, $operation->id, $operation->details->type, $operation->details->tradeDate,
            $operation->sequence, $quantity, $quantity, $cost,
        );
        $state->lots[] = $lot;
        $state->poolQuantity = $state->poolQuantity->add($quantity);
        $state->poolCost = null === $state->poolCost || null === $cost ? null : $state->poolCost->add($cost);
        $state->first ??= $operation->details->tradeDate;
        $movements[$instrumentId] ??= [];
        $movements[$instrumentId][] = self::movement($operation, $quantity, $cost, $lot->lotId);
    }

    /**
     * @param array<string, LedgerState>       $states
     * @param array<string, list<LotMovement>> $movements
     */
    private function dispose(array &$states, array &$movements, PortfolioOperation $operation, string $instrumentId, CalculatedDecimal $quantity, int $legIndex = 0): void
    {
        $state = $states[$instrumentId];
        if ($state->poolQuantity->compareTo($quantity) < 0) {
            throw self::failure('position_would_go_negative', $operation, $instrumentId);
        }
        $remaining = $quantity;
        $newLots = [];
        $movements[$instrumentId] ??= [];
        foreach ($state->lots as $lot) {
            if ($remaining->isZero()) {
                $newLots[] = $lot;
                continue;
            }
            $consumed = $lot->openQuantity->compareTo($remaining) <= 0 ? $lot->openQuantity : $remaining;
            $relievedCost = null === $lot->costBasis
                ? null
                : (0 === $consumed->compareTo($lot->openQuantity)
                    ? $lot->costBasis
                    : $lot->costBasis->proportion($consumed, $lot->openQuantity, DecimalValue::MAX_SCALE, RoundingMode::HALF_EVEN));
            $openQuantity = $lot->openQuantity->subtract($consumed);
            $openCost = null === $lot->costBasis || null === $relievedCost ? null : $lot->costBasis->subtract($relievedCost);
            $movements[$instrumentId][] = self::movement(
                $operation, $consumed->negate(), $relievedCost?->negate(), $lot->lotId,
            );
            if (!$openQuantity->isZero()) {
                $newLots[] = new OpenLot(
                    $lot->lotId, $lot->originOperationId, $lot->originType, $lot->acquiredOn, $lot->originSequence,
                    $lot->originalQuantity, $openQuantity, $openCost,
                );
            }
            $remaining = $remaining->subtract($consumed);
        }
        $poolQuantity = $state->poolQuantity;
        $releaseKey = 0 === $legIndex ? $operation->id : $operation->id.':'.$legIndex;
        $state->released[$releaseKey] = null;
        if (null !== $state->poolCost) {
            $relief = 0 === $quantity->compareTo($poolQuantity)
                ? $state->poolCost
                : $state->poolCost->proportion($quantity, $poolQuantity, DecimalValue::MAX_SCALE, RoundingMode::HALF_EVEN);
            $state->released[$releaseKey] = $relief;
            $state->poolCost = $state->poolCost->subtract($relief);
        }
        $state->poolQuantity = $poolQuantity->subtract($quantity);
        $state->lots = $newLots;
        if ($state->poolQuantity->isZero()) {
            $state->poolCost = CalculatedDecimal::zero();
        }
    }

    /**
     * @param array<string, array{increase: bool, decrease: bool}> $directions
     *
     * @return list<string>
     */
    private static function orderDependentDates(array $directions): array
    {
        $dates = [];
        foreach ($directions as $date => $direction) {
            if ($direction['increase'] && $direction['decrease']) {
                $dates[] = $date;
            }
        }
        sort($dates);

        return $dates;
    }

    public static function movement(PortfolioOperation $operation, CalculatedDecimal $quantityDelta, ?CalculatedDecimal $costDelta, string $lotId): LotMovement
    {
        return new LotMovement(
            $operation->id, $operation->details->tradeDate, $operation->sequence, $operation->details->type,
            $quantityDelta, $costDelta, $lotId,
        );
    }

    public static function failure(string $code, PortfolioOperation $operation, string $instrumentId): PositionReplayFailed
    {
        return new PositionReplayFailed($code, [
            'operationId' => $operation->id,
            'tradeDate' => $operation->details->tradeDate->format('Y-m-d'),
            'instrumentId' => $instrumentId,
        ]);
    }

    private static function compareOperations(PortfolioOperation $left, PortfolioOperation $right): int
    {
        return [$left->details->tradeDate->format('Y-m-d'), $left->sequence, $left->id]
            <=> [$right->details->tradeDate->format('Y-m-d'), $right->sequence, $right->id];
    }

    public static function compareLots(OpenLot $left, OpenLot $right): int
    {
        return [$left->acquiredOn->format('Y-m-d'), $left->originSequence, $left->lotId]
            <=> [$right->acquiredOn->format('Y-m-d'), $right->originSequence, $right->lotId];
    }
}
