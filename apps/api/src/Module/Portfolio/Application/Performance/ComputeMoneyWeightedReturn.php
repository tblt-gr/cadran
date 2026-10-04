<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Portfolio\Domain\Performance\MoneyWeightedReturnCalculator;
use App\Module\Portfolio\Domain\Performance\PerformanceFinding;
use App\Module\Portfolio\Domain\Performance\PerformanceQuality;

final readonly class ComputeMoneyWeightedReturn
{
    public function __construct(private MoneyWeightedReturnCalculator $calculator)
    {
    }

    /** @param list<PerformanceFinding> $blocking
     * @param list<PerformanceFinding> $warnings
     */
    public function __invoke(PerformancePeriodData $data, array $blocking, array $warnings): MoneyWeightedFigure
    {
        if ([] !== $blocking || null === $data->start || null === $data->end) {
            return new MoneyWeightedFigure(null, null, null, [], 0, PerformanceQuality::NOT_CALCULABLE, [...$blocking, ...$warnings]);
        }
        $calculation = $this->calculator->calculate($data->start, $data->end, $data->operations);
        $result = $calculation['result'];
        $findings = null === $result->reason ? $warnings : [new PerformanceFinding($result->reason), ...$warnings];

        return new MoneyWeightedFigure(
            $result->annualizedRate, $result->periodRate, $result->iterations, $calculation['cashFlows'], $calculation['cashFlowCount'],
            PerformanceQuality::of(PerformanceQuality::EXACT, null !== $result->reason, [] !== $warnings), $findings,
        );
    }
}
