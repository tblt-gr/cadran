<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Positions;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;

/** Pure replay of active operations. No result produced here is persisted. */
final class PositionLedger
{
    private const string UNKNOWN_COST = 'UNKNOWN_LOT_COST';

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
        $directions = $this->directions($operations);

        foreach ($operations as $operation) {
            if (!$operation->isActive()) {
                continue;
            }
            $details = $operation->details;
            if (null !== $details->instrumentId) {
                $states[$details->instrumentId] ??= new LedgerState();
                $states[$details->instrumentId]->last = $details->tradeDate;
            }
            if (null !== $details->counterInstrumentId) {
                $states[$details->counterInstrumentId] ??= new LedgerState();
                $states[$details->counterInstrumentId]->last = $details->tradeDate;
            }

            match ($details->type) {
                OperationType::BUY => $this->acquire(
                    $states, $movements, $operation, self::required($details->instrumentId),
                    self::calculated(self::requiredDecimal($details->quantity)),
                    self::acquisitionCost($details->grossAmount, $details->fees, $details->taxes),
                ),
                OperationType::SECURITY_TRANSFER_IN => $this->acquire(
                    $states, $movements, $operation, self::required($details->instrumentId),
                    self::calculated(self::requiredDecimal($details->quantity)), self::nullableCalculated($details->costBasis),
                ),
                OperationType::SELL, OperationType::SECURITY_TRANSFER_OUT => $this->dispose(
                    $states, $movements, $operation, self::required($details->instrumentId),
                    self::calculated(self::requiredDecimal($details->quantity)),
                ),
                OperationType::SPLIT => $this->split(
                    $states, $movements, $operation, self::required($details->instrumentId),
                    $quantityPrecisions[self::required($details->instrumentId)] ?? 0,
                ),
                OperationType::MERGER => $this->merge(
                    $states, $movements, $operation, self::required($details->instrumentId),
                    self::required($details->counterInstrumentId),
                    self::calculated(self::requiredDecimal($details->counterQuantity)),
                    $quantityPrecisions[self::required($details->counterInstrumentId)] ?? 0,
                ),
                OperationType::SPIN_OFF => $this->spinOff(
                    $states, $movements, $operation, self::required($details->instrumentId),
                    self::required($details->counterInstrumentId),
                    self::calculated(self::requiredDecimal($details->counterQuantity)),
                    self::calculated(self::requiredDecimal($details->costAllocationRatio)),
                    $quantityPrecisions[self::required($details->counterInstrumentId)] ?? 0,
                ),
                default => null,
            };
        }

        $positions = [];
        foreach ($states as $instrumentId => $state) {
            $quantity = $state->poolQuantity;
            $fifoCost = self::lotCost($state->lots);
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

        return new PositionLedgerResult($positions, $movements);
    }

    /**
     * @param array<string, LedgerState>       $states
     * @param array<string, list<LotMovement>> $movements
     */
    private function acquire(array &$states, array &$movements, PortfolioOperation $operation, string $instrumentId, CalculatedDecimal $quantity, ?CalculatedDecimal $cost): void
    {
        $state = $states[$instrumentId];
        $lot = new OpenLot(
            $operation->id.'#0', $operation->id, $operation->details->type, $operation->details->tradeDate,
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
    private function dispose(array &$states, array &$movements, PortfolioOperation $operation, string $instrumentId, CalculatedDecimal $quantity): void
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
        if (null !== $state->poolCost) {
            $relief = 0 === $quantity->compareTo($poolQuantity)
                ? $state->poolCost
                : $state->poolCost->proportion($quantity, $poolQuantity, DecimalValue::MAX_SCALE, RoundingMode::HALF_EVEN);
            $state->poolCost = $state->poolCost->subtract($relief);
        }
        $state->poolQuantity = $poolQuantity->subtract($quantity);
        $state->lots = $newLots;
        if ($state->poolQuantity->isZero()) {
            $state->poolCost = CalculatedDecimal::zero();
        }
    }

    /**
     * @param array<string, LedgerState>       $states
     * @param array<string, list<LotMovement>> $movements
     */
    private function split(array &$states, array &$movements, PortfolioOperation $operation, string $instrumentId, int $precision): void
    {
        $state = $states[$instrumentId];
        if ($state->poolQuantity->isZero()) {
            throw self::failure('corporate_action_without_position', $operation, $instrumentId);
        }
        $numerator = self::calculated(self::requiredDecimal($operation->details->ratioNumerator));
        $denominator = self::calculated(self::requiredDecimal($operation->details->ratioDenominator));
        $lots = [];
        $movements[$instrumentId] ??= [];
        foreach ($state->lots as $lot) {
            $expanded = $lot->openQuantity->proportion($numerator, $denominator, DecimalValue::MAX_SCALE, RoundingMode::HALF_EVEN);
            $accepted = $expanded->round($precision, RoundingMode::DOWN);
            if (0 !== $expanded->compareTo($accepted)) {
                throw self::failure('split_fraction_unsupported', $operation, $instrumentId);
            }
            $lots[] = new OpenLot(
                $lot->lotId, $lot->originOperationId, $lot->originType, $lot->acquiredOn, $lot->originSequence,
                $lot->originalQuantity, $accepted, $lot->costBasis,
            );
            $movements[$instrumentId][] = self::movement(
                $operation, $accepted->subtract($lot->openQuantity), CalculatedDecimal::zero(), $lot->lotId,
            );
        }
        $newQuantity = $state->poolQuantity->proportion($numerator, $denominator, $precision, RoundingMode::DOWN);
        $state->lots = $lots;
        $state->poolQuantity = $newQuantity;
    }

    /**
     * @param array<string, LedgerState>       $states
     * @param array<string, list<LotMovement>> $movements
     */
    private function merge(array &$states, array &$movements, PortfolioOperation $operation, string $sourceId, string $targetId, CalculatedDecimal $counterQuantity, int $precision): void
    {
        $source = $states[$sourceId];
        if ($source->poolQuantity->isZero()) {
            throw self::failure('corporate_action_without_position', $operation, $sourceId);
        }
        $sourceLots = $source->lots;
        $sourceQuantity = $source->poolQuantity;
        $sourcePoolCost = $source->poolCost;
        $movements[$sourceId] ??= [];
        foreach ($sourceLots as $lot) {
            $movements[$sourceId][] = self::movement(
                $operation, $lot->openQuantity->negate(), $lot->costBasis?->negate(), $lot->lotId,
            );
        }
        $source->lots = [];
        $source->poolQuantity = CalculatedDecimal::zero();
        $source->poolCost = CalculatedDecimal::zero();

        $this->produceCorporateLots(
            $states, $movements, $operation, $targetId, $sourceLots, $sourceQuantity, $counterQuantity,
            $precision, false, null, $sourcePoolCost,
        );
    }

    /**
     * @param array<string, LedgerState>       $states
     * @param array<string, list<LotMovement>> $movements
     */
    private function spinOff(array &$states, array &$movements, PortfolioOperation $operation, string $sourceId, string $targetId, CalculatedDecimal $counterQuantity, CalculatedDecimal $ratio, int $precision): void
    {
        $source = $states[$sourceId];
        if ($source->poolQuantity->isZero()) {
            throw self::failure('corporate_action_without_position', $operation, $sourceId);
        }
        $originalLots = $source->lots;
        $parentLots = [];
        $movements[$sourceId] ??= [];
        foreach ($originalLots as $lot) {
            $allocated = null === $lot->costBasis
                ? null
                : $lot->costBasis->proportion($ratio, CalculatedDecimal::one(), DecimalValue::MAX_SCALE, RoundingMode::HALF_EVEN);
            $remaining = null === $lot->costBasis || null === $allocated ? null : $lot->costBasis->subtract($allocated);
            $parentLots[] = new OpenLot(
                $lot->lotId, $lot->originOperationId, $lot->originType, $lot->acquiredOn, $lot->originSequence,
                $lot->originalQuantity, $lot->openQuantity, $remaining,
            );
            $movements[$sourceId][] = self::movement(
                $operation, CalculatedDecimal::zero(), $allocated?->negate(), $lot->lotId,
            );
        }
        $source->lots = $parentLots;
        $allocatedPool = null === $source->poolCost
            ? null
            : $source->poolCost->proportion($ratio, CalculatedDecimal::one(), DecimalValue::MAX_SCALE, RoundingMode::HALF_EVEN);
        if (null !== $source->poolCost && null !== $allocatedPool) {
            $source->poolCost = $source->poolCost->subtract($allocatedPool);
        }

        $this->produceCorporateLots(
            $states, $movements, $operation, $targetId, $originalLots, $source->poolQuantity, $counterQuantity,
            $precision, true, $ratio, $allocatedPool,
        );
    }

    /**
     * @param array<string, LedgerState>       $states
     * @param array<string, list<LotMovement>> $movements
     * @param list<OpenLot>                    $sourceLots
     */
    private function produceCorporateLots(
        array &$states,
        array &$movements,
        PortfolioOperation $operation,
        string $targetId,
        array $sourceLots,
        CalculatedDecimal $sourceQuantity,
        CalculatedDecimal $counterQuantity,
        int $precision,
        bool $allocateCost,
        ?CalculatedDecimal $allocationRatio,
        ?CalculatedDecimal $poolCostContribution,
    ): void {
        $target = $states[$targetId];
        $assigned = CalculatedDecimal::zero();
        $carriedCost = CalculatedDecimal::zero();
        $carriedCostKnown = true;
        $lastIndex = count($sourceLots) - 1;
        $movements[$targetId] ??= [];
        foreach ($sourceLots as $index => $sourceLot) {
            $quantity = $index === $lastIndex
                ? $counterQuantity->subtract($assigned)
                : $counterQuantity->proportion($sourceLot->openQuantity, $sourceQuantity, $precision, RoundingMode::DOWN);
            $assigned = $assigned->add($quantity);
            $cost = $sourceLot->costBasis;
            if ($allocateCost && null !== $cost && null !== $allocationRatio) {
                $cost = $cost->proportion($allocationRatio, CalculatedDecimal::one(), DecimalValue::MAX_SCALE, RoundingMode::HALF_EVEN);
            }
            if ($quantity->isZero()) {
                if (null === $cost) {
                    $carriedCostKnown = false;
                } elseif ($carriedCostKnown) {
                    $carriedCost = $carriedCost->add($cost);
                }
                continue;
            }
            $cost = null === $cost || !$carriedCostKnown ? null : $carriedCost->add($cost);
            $carriedCost = CalculatedDecimal::zero();
            $carriedCostKnown = true;
            $lot = new OpenLot(
                $operation->id.'#'.$index, $sourceLot->originOperationId, $sourceLot->originType, $sourceLot->acquiredOn,
                $sourceLot->originSequence, $quantity, $quantity, $cost,
            );
            $target->lots[] = $lot;
            $target->first = null === $target->first || $lot->acquiredOn < $target->first ? $lot->acquiredOn : $target->first;
            $movements[$targetId][] = self::movement($operation, $quantity, $cost, $lot->lotId);
        }
        usort($target->lots, self::compareLots(...));
        $target->poolQuantity = $target->poolQuantity->add($counterQuantity);
        $target->poolCost = null === $target->poolCost || null === $poolCostContribution
            ? null
            : $target->poolCost->add($poolCostContribution);
    }

    /**
     * @param list<PortfolioOperation> $operations
     *
     * @return array<string, array<string, array{increase: bool, decrease: bool}>>
     */
    private function directions(array $operations): array
    {
        $directions = [];
        foreach ($operations as $operation) {
            if (!$operation->isActive()) {
                continue;
            }
            $date = $operation->details->tradeDate->format('Y-m-d');
            $type = $operation->details->type;
            $main = $operation->details->instrumentId;
            $counter = $operation->details->counterInstrumentId;
            if (null !== $main) {
                $directions[$main][$date] ??= ['increase' => false, 'decrease' => false];
                $direction = &$directions[$main][$date];
                if (in_array($type, [OperationType::BUY, OperationType::SECURITY_TRANSFER_IN], true)) {
                    $direction['increase'] = true;
                } elseif (in_array($type, [OperationType::SELL, OperationType::SECURITY_TRANSFER_OUT, OperationType::MERGER], true)) {
                    $direction['decrease'] = true;
                } elseif (OperationType::SPLIT === $type) {
                    $comparison = self::requiredDecimal($operation->details->ratioNumerator)->compareTo(self::requiredDecimal($operation->details->ratioDenominator));
                    $direction[$comparison > 0 ? 'increase' : 'decrease'] = true;
                }
            }
            if (null !== $counter && in_array($type, [OperationType::MERGER, OperationType::SPIN_OFF], true)) {
                $directions[$counter][$date] ??= ['increase' => false, 'decrease' => false];
                $directions[$counter][$date]['increase'] = true;
            }
        }

        return $directions;
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

    /** @param list<OpenLot> $lots */
    private static function lotCost(array $lots): ?CalculatedDecimal
    {
        $cost = CalculatedDecimal::zero();
        foreach ($lots as $lot) {
            if (null === $lot->costBasis) {
                return null;
            }
            $cost = $cost->add($lot->costBasis);
        }

        return $cost;
    }

    private static function movement(PortfolioOperation $operation, CalculatedDecimal $quantityDelta, ?CalculatedDecimal $costDelta, string $lotId): LotMovement
    {
        return new LotMovement(
            $operation->id, $operation->details->tradeDate, $operation->sequence, $operation->details->type,
            $quantityDelta, $costDelta, $lotId,
        );
    }

    private static function failure(string $code, PortfolioOperation $operation, string $instrumentId): PositionReplayFailed
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

    private static function compareLots(OpenLot $left, OpenLot $right): int
    {
        return [$left->acquiredOn->format('Y-m-d'), $left->originSequence, $left->lotId]
            <=> [$right->acquiredOn->format('Y-m-d'), $right->originSequence, $right->lotId];
    }

    private static function acquisitionCost(?DecimalValue $gross, ?DecimalValue $fees, ?DecimalValue $taxes): CalculatedDecimal
    {
        return self::calculated(self::requiredDecimal($gross))
            ->add(self::nullableCalculated($fees) ?? CalculatedDecimal::zero())
            ->add(self::nullableCalculated($taxes) ?? CalculatedDecimal::zero());
    }

    private static function calculated(DecimalValue $value): CalculatedDecimal
    {
        return CalculatedDecimal::fromDecimal($value);
    }

    private static function nullableCalculated(?DecimalValue $value): ?CalculatedDecimal
    {
        return null === $value ? null : self::calculated($value);
    }

    private static function required(?string $value): string
    {
        return $value ?? throw new \LogicException('The operation field matrix guarantees this identifier.');
    }

    private static function requiredDecimal(?DecimalValue $value): DecimalValue
    {
        return $value ?? throw new \LogicException('The operation field matrix guarantees this decimal.');
    }
}
