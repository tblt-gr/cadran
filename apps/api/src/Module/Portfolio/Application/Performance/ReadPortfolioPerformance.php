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
use App\Module\Portfolio\Domain\Performance\PerformanceAmount;
use App\Module\Portfolio\Domain\Performance\PerformancePeriod;
use App\Module\Portfolio\Domain\Performance\PerformanceQuality;
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

        return new PortfolioPerformance(
            $account->id, $account->assetCode->toString(), $period, $data->start, $data->end, $precision,
            $total?->gain, $total?->netFlows,
            $total->startValue ?? $canonical($data->start?->totalValue), $total->endValue ?? $canonical($data->end?->totalValue),
            $quality, [...$blocking, ...$warnings],
            $total->decomposition ?? GainDecomposition::unavailable($blocking[0]->code ?? null),
            array_map(static fn (PortfolioOperation $operation): array => self::flow($operation, $precision), array_slice($flows, 0, self::MAX_FLOWS)),
            count($flows), array_slice($sources, 0, PortfolioExplanation::MAX_SOURCES), count($sources),
            ($this->modifiedDietz)($workspace, $account, $period, $data, $today),
        );
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
