<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Positions;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Portfolio\Domain\LegDirection;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;

/** Lot transformations of splits, mergers and spin-offs, plus the per-day direction scan of operations. */
final class CorporateActionLots
{
    /**
     * @param array<string, LedgerState>       $states
     * @param array<string, list<LotMovement>> $movements
     */
    public function split(array &$states, array &$movements, PortfolioOperation $operation, string $instrumentId, int $precision): void
    {
        $state = $states[$instrumentId];
        if ($state->poolQuantity->isZero()) {
            throw PositionLedger::failure('corporate_action_without_position', $operation, $instrumentId);
        }
        $numerator = LedgerValues::calculated(LedgerValues::requiredDecimal($operation->details->ratioNumerator));
        $denominator = LedgerValues::calculated(LedgerValues::requiredDecimal($operation->details->ratioDenominator));
        $lots = [];
        $movements[$instrumentId] ??= [];
        foreach ($state->lots as $lot) {
            $expanded = $lot->openQuantity->proportion($numerator, $denominator, DecimalValue::MAX_SCALE, RoundingMode::HALF_EVEN);
            $accepted = $expanded->round($precision, RoundingMode::DOWN);
            if (0 !== $expanded->compareTo($accepted)) {
                throw PositionLedger::failure('split_fraction_unsupported', $operation, $instrumentId);
            }
            $lots[] = new OpenLot(
                $lot->lotId, $lot->originOperationId, $lot->originType, $lot->acquiredOn, $lot->originSequence,
                $lot->originalQuantity, $accepted, $lot->costBasis,
            );
            $movements[$instrumentId][] = PositionLedger::movement(
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
    public function merge(array &$states, array &$movements, PortfolioOperation $operation, string $sourceId, string $targetId, CalculatedDecimal $counterQuantity, int $precision): void
    {
        $source = $states[$sourceId];
        if ($source->poolQuantity->isZero()) {
            throw PositionLedger::failure('corporate_action_without_position', $operation, $sourceId);
        }
        $sourceLots = $source->lots;
        $sourceQuantity = $source->poolQuantity;
        $sourcePoolCost = $source->poolCost;
        $movements[$sourceId] ??= [];
        foreach ($sourceLots as $lot) {
            $movements[$sourceId][] = PositionLedger::movement(
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
    public function spinOff(array &$states, array &$movements, PortfolioOperation $operation, string $sourceId, string $targetId, CalculatedDecimal $counterQuantity, CalculatedDecimal $ratio, int $precision): void
    {
        $source = $states[$sourceId];
        if ($source->poolQuantity->isZero()) {
            throw PositionLedger::failure('corporate_action_without_position', $operation, $sourceId);
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
            $movements[$sourceId][] = PositionLedger::movement(
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
            $movements[$targetId][] = PositionLedger::movement($operation, $quantity, $cost, $lot->lotId);
        }
        usort($target->lots, PositionLedger::compareLots(...));
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
    public function directions(array $operations): array
    {
        $directions = [];
        foreach ($operations as $operation) {
            if (!$operation->isActive()) {
                continue;
            }
            $date = $operation->details->tradeDate->format('Y-m-d');
            foreach ($operation->details->legs as $leg) {
                $directions[$leg->instrumentId][$date] ??= ['increase' => false, 'decrease' => false];
                $directions[$leg->instrumentId][$date][LegDirection::IN === $leg->direction ? 'increase' : 'decrease'] = true;
            }
            $type = $operation->details->type;
            $main = $operation->details->instrumentId;
            $counter = $operation->details->counterInstrumentId;
            if (null !== $main) {
                $directions[$main][$date] ??= ['increase' => false, 'decrease' => false];
                $direction = &$directions[$main][$date];
                if (in_array($type, [OperationType::BUY, OperationType::SECURITY_TRANSFER_IN, OperationType::EURO_FUND_INTEREST, OperationType::PROFIT_SHARING], true)) {
                    $direction['increase'] = true;
                } elseif (in_array($type, [OperationType::SELL, OperationType::SECURITY_TRANSFER_OUT, OperationType::MERGER, OperationType::MANAGEMENT_FEE, OperationType::SOCIAL_CONTRIBUTION], true)) {
                    $direction['decrease'] = true;
                } elseif (OperationType::SPLIT === $type) {
                    $comparison = LedgerValues::requiredDecimal($operation->details->ratioNumerator)->compareTo(LedgerValues::requiredDecimal($operation->details->ratioDenominator));
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
}
