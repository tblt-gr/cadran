<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Accounts\Domain\Account;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\PortfolioAccountGate;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\Performance\ChainedIndex;
use App\Module\Portfolio\Domain\Performance\PerformanceAmount;
use App\Module\Portfolio\Domain\Performance\PerformancePeriod;
use App\Module\Portfolio\Domain\Performance\PerformanceScope;
use App\Module\Portfolio\Domain\PortfolioOperation;

/**
 * The twelve months, the year and the whole life of a portfolio account. The account history is read once and bounded,
 * and every period goes through the single-period figures, so a month here is the `MONTH` scope of the same month.
 */
final readonly class ReadPortfolioAnnualPerformance
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private WorkspaceCalendar $calendar,
        private PerformancePeriodBuilder $builder,
        private ComputePortfolioPerformance $compute,
    ) {
    }

    public function __invoke(string $accountId, ?string $yearLiteral): PortfolioAnnualPerformance
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $account = $this->gate->forRead($workspace, $accountId);
        $today = $this->calendar->today();
        $firstYear = (int) $account->openedOn->format('Y');
        $lastYear = (int) $today->format('Y');
        if (null === $yearLiteral || 1 !== preg_match('/^[0-9]{4}$/D', $yearLiteral) || (int) $yearLiteral < $firstYear || (int) $yearLiteral > $lastYear) {
            throw new InvalidPortfolioOperation('The year is between the opening year of the account and the current year.', 'invalid_performance_period');
        }
        $year = (int) $yearLiteral;
        $history = $this->builder->history($workspace, $account);

        $months = [];
        foreach (range(1, 12) as $number) {
            $label = sprintf('%d-%02d', $year, $number);
            $first = new \DateTimeImmutable($label.'-01', new \DateTimeZone('UTC'));
            $months[] = match (true) {
                $first->modify('last day of this month') < $account->openedOn->modify('first day of this month') => new AnnualPerformanceMonth($label, AnnualPerformanceMonth::BEFORE_OPENING),
                $first > $today => new AnnualPerformanceMonth($label, AnnualPerformanceMonth::FUTURE),
                default => $this->month($account, $label, $today, $history, $workspace),
            };
        }

        $yearTotal = ($this->compute)($workspace, $account, PerformancePeriod::of(PerformanceScope::YEAR->value, $yearLiteral, $today, $account->openedOn), $today, $history);
        $inception = ($this->compute)($workspace, $account, PerformancePeriod::of(PerformanceScope::INCEPTION->value, null, $today, $account->openedOn), $today, $history);

        return new PortfolioAnnualPerformance(
            $account->id, $account->assetCode->toString(), $year, $firstYear, $lastYear,
            $this->activeYears($history, $firstYear, $lastYear),
            $this->withIndex($months, $account, $year, $history), $yearTotal, $inception,
        );
    }

    /**
     * A year with no active operation is absent from the selector. Several quiet months are
     * enough to drop it. An account with no operation at all still offers the current year.
     *
     * @param list<PortfolioOperation> $history
     *
     * @return list<int>
     */
    private function activeYears(array $history, int $firstYear, int $lastYear): array
    {
        $years = [];
        foreach ($history as $operation) {
            $operationYear = (int) $operation->details->tradeDate->format('Y');
            if ($operationYear >= $firstYear && $operationYear <= $lastYear) {
                $years[$operationYear] = true;
            }
        }
        if ([] === $years) {
            $years[$lastYear] = true;
        }
        $list = array_keys($years);
        sort($list);

        return $list;
    }

    /** @param list<PortfolioOperation> $history */
    private function month(Account $account, string $label, \DateTimeImmutable $today, array $history, WorkspaceScope $workspace): AnnualPerformanceMonth
    {
        $performance = ($this->compute)($workspace, $account, PerformancePeriod::of(PerformanceScope::MONTH->value, $label, $today, $account->openedOn), $today, $history);
        $end = $performance->end;
        $endCash = null === $end || null === $performance->gain ? null : PerformanceAmount::canonical(
            CalculatedDecimal::fromDecimal(ExactDecimal::subtract($end->totalValue, $end->securitiesValue)),
            $performance->displayPrecision,
        );

        return new AnnualPerformanceMonth($label, null === $performance->gain ? AnnualPerformanceMonth::NOT_CALCULABLE : AnnualPerformanceMonth::CALCULATED, $performance, $endCash);
    }

    /**
     * The index chain begins at the first month the account has anything to chain: the later of the opening month and the
     * month of the first operation, or January of the year when that is earlier.
     *
     * @param list<AnnualPerformanceMonth> $months
     * @param list<PortfolioOperation>     $history
     *
     * @return list<AnnualPerformanceMonth>
     */
    private function withIndex(array $months, Account $account, int $year, array $history): array
    {
        $start = isset($history[0]) ? max($account->openedOn, $history[0]->details->tradeDate) : $account->openedOn;
        $firstIndex = max(0, ((int) $start->format('Y') - $year) * 12 + (int) $start->format('n') - 1);
        $rates = array_map(static function (AnnualPerformanceMonth $month): ?string {
            $rate = $month->performance?->modifiedDietz->unroundedRate;

            return null === $rate ? null : $rate->toString();
        }, $months);
        $series = ChainedIndex::series($rates, $firstIndex);

        return array_map(
            static fn (AnnualPerformanceMonth $month, ?string $index): AnnualPerformanceMonth => null === $month->performance
                ? $month
                : new AnnualPerformanceMonth($month->month, $month->status, $month->performance, $month->endCash, $index),
            $months,
            $series,
        );
    }
}
