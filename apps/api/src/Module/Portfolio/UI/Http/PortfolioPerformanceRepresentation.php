<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Portfolio\Application\Performance\PortfolioPerformance;
use App\Module\Portfolio\Domain\Cash\PortfolioCostPolicy;
use App\Module\Portfolio\Domain\Performance\ModifiedDietzCalculator;
use App\Module\Portfolio\Domain\Performance\PerformanceAmount;
use App\Module\Portfolio\Domain\Performance\PerformanceFinding;
use App\Module\Portfolio\Domain\Performance\PerformanceMethod;
use App\Module\Portfolio\Domain\Performance\PeriodAnchor;
use App\Module\Portfolio\Domain\Performance\TimeWeightedReturnCalculator;

final class PortfolioPerformanceRepresentation
{
    /** @return array<string, mixed> */
    public static function of(PortfolioPerformance $performance): array
    {
        $start = $performance->start;
        $end = $performance->end;
        $decomposition = $performance->decomposition;

        return [
            'accountId' => $performance->accountId,
            'assetCode' => $performance->assetCode,
            'scope' => $performance->period->scope->value,
            'period' => $performance->period->label,
            'methodVersion' => PerformanceMethod::VERSION,
            'costPolicy' => PortfolioCostPolicy::PERFORMANCE_BASIS,
            'start' => self::anchor($start, $performance->displayPrecision),
            'end' => self::anchor($end, $performance->displayPrecision),
            'flows' => $performance->flows,
            'flowCount' => $performance->flowCount,
            'netFlows' => $performance->netFlows,
            'gain' => [
                'value' => $performance->gain,
                ...self::envelope($performance, PerformanceMethod::TOTAL_GAIN),
                'startValue' => $performance->startValue,
                'endValue' => $performance->endValue,
                'netFlows' => $performance->netFlows,
                'decomposition' => [
                    'status' => $decomposition->status->value,
                    'reason' => $decomposition->reason?->value,
                    'realized' => $decomposition->realized,
                    'unrealizedChange' => $decomposition->unrealizedChange,
                    'income' => $decomposition->income,
                    'fees' => $decomposition->fees,
                    'taxes' => $decomposition->taxes,
                    'corporateActionCash' => $decomposition->corporateActionCash,
                    'capitalizedCosts' => $decomposition->capitalizedCosts,
                    'residual' => $decomposition->residual,
                    'feesPaid' => $decomposition->feesPaid,
                ],
            ],
            'modifiedDietz' => self::modifiedDietz($performance),
            'timeWeighted' => self::timeWeighted($performance),
            'moneyWeighted' => self::moneyWeighted($performance),
        ];
    }

    /**
     * One line of the annual table, taken from the same representation as the single-period endpoint so both agree.
     *
     * @return array<string, mixed>
     */
    public static function line(PortfolioPerformance $performance): array
    {
        $gain = self::envelope($performance, PerformanceMethod::TOTAL_GAIN);
        $decomposition = $performance->decomposition;
        $dietz = self::envelope($performance, PerformanceMethod::MODIFIED_DIETZ);
        $twr = self::envelope($performance, PerformanceMethod::TWR);
        $mwr = self::envelope($performance, PerformanceMethod::MWR);
        $twrRate = $performance->timeWeighted->rate;

        return [
            'from' => $gain['from'], 'to' => $gain['to'],
            'startValue' => $performance->startValue, 'endValue' => $performance->endValue,
            'deposits' => $performance->deposits, 'withdrawals' => $performance->withdrawals, 'netFlows' => $performance->netFlows,
            'gain' => $performance->gain,
            'realized' => $decomposition->realized, 'unrealizedChange' => $decomposition->unrealizedChange,
            'income' => $decomposition->income, 'fees' => $decomposition->fees, 'feesPaid' => $decomposition->feesPaid, 'taxes' => $decomposition->taxes,
            'gainQuality' => $gain['quality'], 'gainReasons' => $gain['reasons'],
            'modifiedDietz' => ['value' => $performance->modifiedDietz->value, 'quality' => $dietz['quality'], 'reasons' => $dietz['reasons']],
            'timeWeighted' => ['value' => null === $twrRate ? null : TimeWeightedReturnCalculator::display($twrRate), 'quality' => $twr['quality'], 'reasons' => $twr['reasons']],
            'moneyWeighted' => [
                'annualizedRate' => $performance->moneyWeighted->annualizedRate, 'periodRate' => $performance->moneyWeighted->periodRate,
                'shortPeriod' => self::shortPeriod($performance), 'quality' => $mwr['quality'], 'reasons' => $mwr['reasons'],
            ],
        ];
    }

    private static function shortPeriod(PortfolioPerformance $performance): bool
    {
        $days = $performance->explanations[PerformanceMethod::MWR->value]->days;

        return null !== $days && $days < 365;
    }

    /** @return array<string, mixed> */
    private static function moneyWeighted(PortfolioPerformance $performance): array
    {
        $figure = $performance->moneyWeighted;

        return [
            'value' => $figure->annualizedRate,
            'annualizedRate' => $figure->annualizedRate,
            'periodRate' => $figure->periodRate,
            ...self::envelope($performance, PerformanceMethod::MWR),
            'shortPeriod' => self::shortPeriod($performance),
            'iterations' => $figure->iterations,
            'cashFlows' => $figure->cashFlows,
            'cashFlowCount' => $figure->cashFlowCount,
            'formula' => 'sum(CF_i * x^(D - day_i)) = 0; annualizedRate = x^365 - 1; periodRate = x^D - 1',
        ];
    }

    /** @return array<string, mixed> */
    private static function timeWeighted(PortfolioPerformance $performance): array
    {
        $figure = $performance->timeWeighted;

        return [
            'value' => null === $figure->rate ? null : TimeWeightedReturnCalculator::display($figure->rate),
            ...self::envelope($performance, PerformanceMethod::TWR),
            'subperiods' => $figure->subperiods,
            'subperiodCount' => $figure->subperiodCount,
            'missingCount' => $figure->missingCount,
        ];
    }

    /** @return array<string, mixed> */
    private static function modifiedDietz(PortfolioPerformance $performance): array
    {
        $figure = $performance->modifiedDietz;

        return [
            'value' => $figure->value,
            ...self::envelope($performance, PerformanceMethod::MODIFIED_DIETZ),
            'averageCapital' => $figure->averageCapital,
            'minCapitalRatio' => ModifiedDietzCalculator::DIETZ_MIN_CAPITAL_RATIO,
            'weights' => $figure->weights,
            'weightCount' => $figure->weightCount,
            'months' => null === $figure->months ? null : array_map(
                static fn (array $month): array => ['month' => $month['month'], 'value' => $month['value'], 'quality' => $month['quality']->value, 'reasons' => self::reasons($month['findings'])],
                $figure->months,
            ),
        ];
    }

    /**
     * The explanation every figure shares, all taken from the one calculator result that produced the value.
     *
     * @return array<string, mixed>
     */
    private static function envelope(PortfolioPerformance $performance, PerformanceMethod $method): array
    {
        $explanation = $performance->explanations[$method->value];

        return [
            'method' => $explanation->method->value,
            'methodVersion' => $explanation->methodVersion,
            'costPolicy' => $explanation->costPolicy,
            'from' => $explanation->from,
            'to' => $explanation->to,
            'days' => $explanation->days,
            'inputs' => $explanation->inputs,
            'freshness' => $explanation->freshness,
            'quality' => $explanation->quality->value,
            'reasons' => self::reasons($explanation->findings),
            'sources' => $explanation->sources,
            'sourceCount' => $explanation->sourceCount,
        ];
    }

    /**
     * @param list<PerformanceFinding> $findings
     *
     * @return list<array{code: string, dates: list<string>}>
     */
    private static function reasons(array $findings): array
    {
        return array_map(static fn (PerformanceFinding $finding): array => ['code' => $finding->code->value, 'dates' => $finding->dates], $findings);
    }

    /** @return array<string, mixed>|null */
    private static function anchor(?PeriodAnchor $anchor, int $precision): ?array
    {
        if (null === $anchor) {
            return null;
        }

        return [
            'kind' => $anchor->kind->value,
            'valuationId' => $anchor->valuationId,
            'date' => $anchor->date->format('Y-m-d'),
            'totalValue' => PerformanceAmount::canonical(CalculatedDecimal::fromDecimal($anchor->totalValue), $precision),
            'detail' => $anchor->detail,
            'flags' => $anchor->flags,
        ];
    }
}
