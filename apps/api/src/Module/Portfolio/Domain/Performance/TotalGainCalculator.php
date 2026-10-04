<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\Positions\PositionLedgerResult;

/**
 * gain = end value - start value - signed external flows, then its decomposition. Pure and exact: nothing is rounded
 * and a missing input yields a null figure with a reason, never zero.
 */
final class TotalGainCalculator
{
    /**
     * @param list<PortfolioOperation> $operations active operations with start date < trade date <= end date
     * @param PositionLedgerResult     $ledger     replay of the account history up to the end anchor
     */
    public function calculate(PeriodAnchor $start, PeriodAnchor $end, array $operations, PositionLedgerResult $ledger, int $displayPrecision): TotalGain
    {
        $startValue = CalculatedDecimal::fromDecimal($start->totalValue);
        $endValue = CalculatedDecimal::fromDecimal($end->totalValue);
        $warnings = self::anchorWarnings($start, $end);

        $netFlows = CalculatedDecimal::zero();
        $transferDates = [];
        foreach ($operations as $operation) {
            if (ExternalFlow::isUnvalued($operation)) {
                $transferDates[] = $operation->details->tradeDate->format('Y-m-d');
                continue;
            }
            $signed = ExternalFlow::signedAmount($operation);
            if (null !== $signed) {
                $netFlows = $netFlows->add(CalculatedDecimal::fromDecimal($signed));
            }
        }
        $startLiteral = PerformanceAmount::canonical($startValue, $displayPrecision);
        $endLiteral = PerformanceAmount::canonical($endValue, $displayPrecision);

        if ([] !== $transferDates) {
            return new TotalGain(
                null, null, $startLiteral, $endLiteral,
                [new PerformanceFinding(PerformanceReason::UNVALUED_SECURITY_TRANSFER, $transferDates)], $warnings,
                GainDecomposition::unavailable(PerformanceReason::UNVALUED_SECURITY_TRANSFER),
            );
        }

        $gain = $endValue->subtract($startValue)->subtract($netFlows);
        $decomposition = $this->decompose($start, $end, $operations, $ledger, $gain, $displayPrecision);
        if (null !== $decomposition['warning']) {
            $warnings[] = $decomposition['warning'];
        }

        return new TotalGain(
            PerformanceAmount::canonical($gain, $displayPrecision),
            PerformanceAmount::canonical($netFlows, $displayPrecision),
            $startLiteral, $endLiteral, [], $warnings, $decomposition['decomposition'],
        );
    }

    /** @return list<PerformanceFinding> */
    private static function anchorWarnings(PeriodAnchor $start, PeriodAnchor $end): array
    {
        $dates = [];
        foreach ([$start, $end] as $anchor) {
            foreach ($anchor->flags as $flag) {
                $dates[$flag][] = $anchor->date->format('Y-m-d');
            }
        }

        $findings = [];
        foreach ($dates as $flag => $flagDates) {
            $findings[] = new PerformanceFinding(PerformanceReason::from((string) $flag), $flagDates);
        }

        return $findings;
    }

    /**
     * @param list<PortfolioOperation> $operations
     *
     * @return array{decomposition: GainDecomposition, warning: ?PerformanceFinding}
     */
    private function decompose(PeriodAnchor $start, PeriodAnchor $end, array $operations, PositionLedgerResult $ledger, CalculatedDecimal $gain, int $displayPrecision): array
    {
        $unavailable = ['decomposition' => GainDecomposition::unavailable(PerformanceReason::UNKNOWN_LOT_COST), 'warning' => null];
        if (!$start->hasKnownCosts() || !$end->hasKnownCosts()) {
            return $unavailable;
        }

        $realized = $income = $fees = $taxes = $corporateCash = $capitalized = CalculatedDecimal::zero();
        foreach ($operations as $operation) {
            $details = $operation->details;
            if (OperationType::SELL === $details->type) {
                $released = $ledger->averageCostReleased[$operation->id] ?? null;
                if (null === $released || null === $details->grossAmount) {
                    return $unavailable;
                }
                $realized = $realized->add(CalculatedDecimal::fromDecimal($details->grossAmount)->subtract($released));
            }
            if (in_array($details->type, [OperationType::DIVIDEND, OperationType::INTEREST], true) && null !== $details->grossAmount) {
                $income = $income->add(CalculatedDecimal::fromDecimal($details->grossAmount));
            }
            if (OperationType::MERGER === $details->type && null !== $details->grossAmount) {
                $corporateCash = $corporateCash->add(CalculatedDecimal::fromDecimal($details->grossAmount));
            }
            $operationFees = self::amount($details->fees);
            $operationTaxes = self::amount($details->taxes);
            if (OperationType::BUY === $details->type) {
                $capitalized = $capitalized->add($operationFees)->add($operationTaxes);
                continue;
            }
            $fees = $fees->subtract($operationFees);
            $taxes = $taxes->subtract($operationTaxes);
        }

        $unrealizedChange = self::unrealized($end)->subtract(self::unrealized($start));
        $explained = $realized->add($unrealizedChange)->add($income)->add($fees)->add($taxes)->add($corporateCash);
        $residual = $gain->subtract($explained);
        $tolerance = CalculatedDecimal::fromDecimal(DecimalValue::fromString('0.'.str_repeat('0', max(0, $displayPrecision)).'5'));
        $absolute = $residual->compareTo(CalculatedDecimal::zero()) < 0 ? $residual->negate() : $residual;
        $warning = $absolute->compareTo($tolerance) > 0
            ? new PerformanceFinding(PerformanceReason::DECOMPOSITION_RESIDUAL, [$end->date->format('Y-m-d')])
            : null;
        $format = static fn (CalculatedDecimal $value): string => PerformanceAmount::canonical($value, $displayPrecision);

        return [
            'decomposition' => new GainDecomposition(
                DecompositionStatus::COMPLETE, null, $format($realized), $format($unrealizedChange), $format($income),
                $format($fees), $format($taxes), $format($corporateCash), $format($capitalized), $format($residual),
            ),
            'warning' => $warning,
        ];
    }

    /** Securities value minus the average cost of the held positions; an opening holds nothing. */
    private static function unrealized(PeriodAnchor $anchor): CalculatedDecimal
    {
        $cost = CalculatedDecimal::zero();
        foreach ($anchor->positions as $position) {
            $cost = $cost->add(CalculatedDecimal::fromDecimal($position->averageCostBasis ?? throw new \LogicException('Costs are known here.')));
        }

        return CalculatedDecimal::fromDecimal($anchor->securitiesValue)->subtract($cost);
    }

    private static function amount(?DecimalValue $value): CalculatedDecimal
    {
        return null === $value ? CalculatedDecimal::zero() : CalculatedDecimal::fromDecimal($value);
    }
}
