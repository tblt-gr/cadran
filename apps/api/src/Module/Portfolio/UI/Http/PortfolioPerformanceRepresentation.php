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
                'method' => 'TOTAL_GAIN',
                'quality' => $performance->quality->value,
                'reasons' => array_map(static fn (PerformanceFinding $finding): array => ['code' => $finding->code->value, 'dates' => $finding->dates], $performance->findings),
                'from' => $start?->date->format('Y-m-d'),
                'to' => $end?->date->format('Y-m-d'),
                'days' => null === $start || null === $end ? null : (int) $start->date->diff($end->date)->format('%a'),
                'sources' => $performance->sources,
                'sourceCount' => $performance->sourceCount,
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
                ],
            ],
            'modifiedDietz' => self::modifiedDietz($performance),
        ];
    }

    /** @return array<string, mixed> */
    private static function modifiedDietz(PortfolioPerformance $performance): array
    {
        $figure = $performance->modifiedDietz;
        $start = $performance->start;
        $end = $performance->end;

        return [
            'value' => $figure->value,
            'method' => 'MODIFIED_DIETZ',
            'quality' => $figure->quality->value,
            'reasons' => self::reasons($figure->findings),
            'from' => $start?->date->format('Y-m-d'),
            'to' => $end?->date->format('Y-m-d'),
            'days' => null === $start || null === $end ? null : (int) $start->date->diff($end->date)->format('%a'),
            'sources' => $performance->sources,
            'sourceCount' => $performance->sourceCount,
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
