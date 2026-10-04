<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\PortfolioValuationPresenter;
use App\Module\Portfolio\Domain\Performance\PerformanceScope;
use App\Module\Portfolio\Domain\Performance\PeriodAnchor;
use App\Module\Portfolio\Domain\Performance\TimeWeightedReturn;
use App\Module\Portfolio\Domain\Performance\TimeWeightedReturnCalculator;
use App\Module\Portfolio\Domain\Valuation\Valuation;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use App\Module\Portfolio\Domain\Valuation\ValuationRepository;

/** Loads active complete observations once; a missing flow-day observation is never filled from another day. */
final readonly class ComputeTimeWeightedReturn
{
    private const int MAX_VALUATIONS = 5000;

    public function __construct(
        private ValuationRepository $valuations,
        private PortfolioValuationPresenter $presenter,
        private TimeWeightedReturnCalculator $calculator,
    ) {
    }

    public function __invoke(WorkspaceScope $workspace, string $accountId, PerformanceScope $scope, PerformancePeriodData $data): TimeWeightedReturn
    {
        if (!$data->isCalculable() || null === $data->start || null === $data->end) {
            return new TimeWeightedReturn(null, [], 0, 0, $data->blocking, $data->warnings);
        }
        $from = $data->start->date;
        $to = $data->end->date;
        if ($this->valuations->count($workspace, $accountId, $from, $to) > self::MAX_VALUATIONS) {
            throw new PerformanceRangeTooDense('The portfolio valuations exceed the performance read bound.');
        }
        $active = $this->valuations->search($workspace, $accountId, $from, $to, self::MAX_VALUATIONS, 0);
        $complete = array_values(array_filter($active, static fn (Valuation $valuation): bool => $valuation->isActive() && ValuationCompleteness::COMPLETE === $valuation->completeness));
        $anchors = [];
        $months = [];
        foreach ($this->presenter->present($workspace, $complete) as $view) {
            $valuation = $view->valuation;
            $anchor = PeriodAnchor::valuation(
                $valuation->id, $valuation->valuedOn,
                $valuation->totalValue ?? throw new \LogicException('A complete valuation has a total.'),
                $valuation->securitiesValue ?? throw new \LogicException('A complete valuation has a securities value.'),
                $valuation->detail->value,
                array_map(static fn ($flag): string => $flag->value, $view->flags), $valuation->positions,
            );
            $anchors[] = $anchor;
            $month = $valuation->valuedOn->format('Y-m');
            if (!isset($months[$month]) || $anchor->date > $months[$month]->date) {
                $months[$month] = $anchor;
            }
        }
        $result = $this->calculator->calculate($data->start, $data->end, $data->operations, $anchors, PerformanceScope::MONTH === $scope ? [] : array_values($months), $data->displayPrecision);

        return new TimeWeightedReturn($result->rate, $result->subperiods, $result->subperiodCount, $result->missingCount, $result->blocking, [...$data->warnings, ...$result->warnings], $result->anchors);
    }
}
