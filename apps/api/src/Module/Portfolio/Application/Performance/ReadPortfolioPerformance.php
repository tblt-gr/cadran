<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Application\PortfolioAccountGate;
use App\Module\Portfolio\Application\PortfolioExplanation;
use App\Module\Portfolio\Application\ReconcilePortfolioPositions;
use App\Module\Portfolio\Domain\Performance\ExternalFlow;
use App\Module\Portfolio\Domain\Performance\GainDecomposition;
use App\Module\Portfolio\Domain\Performance\ModifiedDietzCalculator;
use App\Module\Portfolio\Domain\Performance\MoneyWeightedReturnSolver;
use App\Module\Portfolio\Domain\Performance\PerformanceAmount;
use App\Module\Portfolio\Domain\Performance\PerformanceFinding;
use App\Module\Portfolio\Domain\Performance\PerformanceMethod;
use App\Module\Portfolio\Domain\Performance\PerformancePeriod;
use App\Module\Portfolio\Domain\Performance\PerformanceQuality;
use App\Module\Portfolio\Domain\Performance\PerformanceScope;
use App\Module\Portfolio\Domain\Performance\TimeWeightedReturn;
use App\Module\Portfolio\Domain\Performance\TotalGainCalculator;
use App\Module\Portfolio\Domain\PortfolioOperation;

/** The total gain of a portfolio account over a month, a year or its whole life, with its decomposition. */
final readonly class ReadPortfolioPerformance
{
    public const int MAX_FLOWS = 100;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private WorkspaceCalendar $calendar,
        private PerformancePeriodBuilder $builder,
        private ReconcilePortfolioPositions $reconcile,
        private TotalGainCalculator $calculator,
        private ComputeModifiedDietz $modifiedDietz,
        private ComputeTimeWeightedReturn $timeWeighted,
        private ComputeMoneyWeightedReturn $moneyWeighted,
        private PerformanceFigureFactory $figures,
    ) {
    }

    public function __invoke(string $accountId, ?string $scope, ?string $periodLiteral): PortfolioPerformance
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $account = $this->gate->forRead($workspace, $accountId);
        $today = $this->calendar->today();
        $period = PerformancePeriod::of($scope, $periodLiteral, $today, $account->openedOn);
        $data = $this->builder->build($workspace, $account, $period, $today);

        $flows = [];
        $total = null;
        if ($data->isCalculable()) {
            $total = $this->calculator->calculate(
                $data->start ?? throw new \LogicException('A calculable period has a start.'),
                $data->end ?? throw new \LogicException('A calculable period has an end.'),
                $data->operations,
                $this->reconcile->replayOf($workspace, $data->history),
                $data->displayPrecision,
            );
            $flows = array_values(array_filter($data->operations, ExternalFlow::isExternal(...)));
        }

        $blocking = [...$data->blocking, ...($total->blocking ?? [])];
        $warnings = [...$data->warnings, ...($total->warnings ?? [])];
        $quality = PerformanceQuality::of(PerformanceQuality::EXACT, [] !== $blocking, [] !== $warnings);
        $sources = $this->sources($data);
        $precision = $data->displayPrecision;
        $canonical = static fn (?DecimalValue $value): ?string => null === $value ? null : PerformanceAmount::canonical(CalculatedDecimal::fromDecimal($value), $precision);

        $gain = $total?->gain;
        $netFlows = $total?->netFlows;
        $startValue = $total->startValue ?? $canonical($data->start?->totalValue);
        $endValue = $total->endValue ?? $canonical($data->end?->totalValue);
        $monthly = PerformanceScope::MONTH === $period->scope;
        $dietz = ($this->modifiedDietz)($workspace, $account, $period, $data, $today);
        $twr = ($this->timeWeighted)($workspace, $account->id, $period->scope, $data);
        $mwr = ($this->moneyWeighted)($data, $blocking, $warnings);
        $findings = [...$blocking, ...$warnings];
        $preview = array_slice($sources, 0, PortfolioExplanation::MAX_SOURCES);
        $twrSources = $this->withAnchors($sources, $twr);

        return new PortfolioPerformance(
            $account->id, $account->assetCode->toString(), $period, $data->start, $data->end, $precision,
            $gain, $netFlows, $startValue, $endValue,
            $quality, $findings,
            $total->decomposition ?? GainDecomposition::unavailable($blocking[0]->code ?? null),
            array_map(static fn (PortfolioOperation $operation): array => self::flow($operation, $precision), array_slice($flows, 0, self::MAX_FLOWS)),
            count($flows), $preview, count($sources),
            $dietz, $twr, $mwr,
            [
                PerformanceMethod::TOTAL_GAIN->value => $this->explain($data, $today, PerformanceMethod::TOTAL_GAIN, ['startValue' => $startValue, 'endValue' => $endValue, 'netFlows' => $netFlows], $quality, $findings, $sources),
                PerformanceMethod::MODIFIED_DIETZ->value => $this->explain($data, $today, PerformanceMethod::MODIFIED_DIETZ, [
                    'startValue' => $monthly ? $canonical($data->start?->totalValue) : null,
                    'gain' => $dietz->gain,
                    'periodDays' => !$monthly || null === $data->start || null === $data->end ? null : (int) $data->start->date->diff($data->end->date)->format('%a'),
                    'denominatorTimesDays' => $dietz->denominatorTimesDays,
                    'averageCapital' => $dietz->averageCapital,
                    'minCapitalRatio' => ModifiedDietzCalculator::DIETZ_MIN_CAPITAL_RATIO,
                ], $dietz->quality, $dietz->findings, $sources),
                PerformanceMethod::TWR->value => $this->explain($data, $today, PerformanceMethod::TWR, [
                    'breakpointCount' => $twr->subperiodCount > 0 ? $twr->subperiodCount + 1 : 0,
                    'subperiodCount' => $twr->subperiodCount,
                ], PerformanceQuality::of(PerformanceMethod::TWR->baseQuality(), null === $twr->rate, [] !== $twr->warnings), [...$twr->blocking, ...$twr->warnings], $twrSources),
                PerformanceMethod::MWR->value => $this->explain($data, $today, PerformanceMethod::MWR, [
                    'workingScale' => MoneyWeightedReturnSolver::WORKING_SCALE,
                    'stopWidth' => MoneyWeightedReturnSolver::STOP_WIDTH,
                    'maxIterations' => MoneyWeightedReturnSolver::MAX_ITERATIONS,
                    'searchRange' => [MoneyWeightedReturnSolver::MIN_FACTOR, MoneyWeightedReturnSolver::MAX_FACTOR],
                    'flowDayCount' => $mwr->cashFlowCount,
                ], $mwr->quality, $mwr->findings, $sources),
            ],
        );
    }

    /**
     * @param array<string, mixed>                                $inputs
     * @param list<PerformanceFinding>                            $findings
     * @param list<array{kind: string, id: string, date: string}> $sources
     */
    private function explain(PerformancePeriodData $data, \DateTimeImmutable $today, PerformanceMethod $method, array $inputs, PerformanceQuality $quality, array $findings, array $sources): PerformanceExplanation
    {
        return $this->figures->explain($method, $data->start, $data->end, $today, $inputs, $quality, $findings, array_slice($sources, 0, PortfolioExplanation::MAX_SOURCES), count($sources));
    }

    /**
     * @param list<array{kind: string, id: string, date: string}> $sources
     *
     * @return list<array{kind: string, id: string, date: string}> the sources plus the valuations the chained subperiods rest on
     */
    private function withAnchors(array $sources, TimeWeightedReturn $twr): array
    {
        $known = array_column($sources, 'id');
        foreach ($twr->anchors as $anchor) {
            if (null !== $anchor->valuationId && !in_array($anchor->valuationId, $known, true)) {
                $sources[] = ['kind' => 'VALUATION', 'id' => $anchor->valuationId, 'date' => $anchor->date->format('Y-m-d')];
                $known[] = $anchor->valuationId;
            }
        }

        return $sources;
    }

    /** @return list<array{kind: string, id: string, date: string}> */
    private function sources(PerformancePeriodData $data): array
    {
        $sources = [];
        foreach ([$data->start, $data->end] as $anchor) {
            if (null !== $anchor && null !== $anchor->valuationId) {
                $sources[] = ['kind' => 'VALUATION', 'id' => $anchor->valuationId, 'date' => $anchor->date->format('Y-m-d')];
            }
        }

        return [...$sources, ...PortfolioExplanation::operations($data->operations)];
    }

    /** @return array{operationId: string, type: string, date: string, amount: ?string} */
    private static function flow(PortfolioOperation $operation, int $precision): array
    {
        $amount = ExternalFlow::signedAmount($operation);

        return [
            'operationId' => $operation->id, 'type' => $operation->details->type->value, 'date' => $operation->details->tradeDate->format('Y-m-d'),
            'amount' => null === $amount ? null : PerformanceAmount::canonical(CalculatedDecimal::fromDecimal($amount), $precision),
        ];
    }
}
