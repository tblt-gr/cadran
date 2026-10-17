<?php

declare(strict_types=1);

namespace App\Module\Import\Application;

use App\Module\Import\Application\Planning\AccountTablePlanner;
use App\Module\Import\Application\Planning\LinePlanner;
use App\Module\Import\Application\Planning\PlanningState;
use App\Module\Import\Application\Planning\PortfolioPlanner;
use App\Module\Import\Application\Planning\SavingsPlanner;
use App\Module\Import\Domain\Issue;
use App\Module\Import\Domain\Mapping\ImportMapping;
use App\Module\Import\Domain\Plan\ImportPlan;
use App\Module\Import\Domain\ReasonCode;
use App\Module\Import\Domain\Workbook\ParsedMonth;
use App\Module\Import\Domain\Workbook\ParsedWorkbook;

/**
 * Turns a parsed workbook and its mapping into the ordered records the import
 * would create, resolving every label against the workspace without writing
 * anything. Within a month the order is: opening records (first month only),
 * income, fixed and variable expenses, refunds, savings transfers, portfolio
 * deposits, portfolio purchases, balance snapshots, portfolio valuations.
 */
final readonly class ImportPlanner
{
    public function __construct(private ImportReferences $references)
    {
    }

    public function plan(ParsedWorkbook $workbook, ImportMapping $mapping): ImportPlan
    {
        $today = $this->references->today()->format('Y-m-d');
        $notStarted = array_values(array_filter($workbook->months, static fn (ParsedMonth $month): bool => sprintf('%04d-%02d-01', $workbook->year, $month->month) > $today));
        $state = new PlanningState($this->references, $mapping, $workbook->year, self::issuesOutside($workbook, $notStarted));

        $imported = $this->references->importedYears();
        if (in_array($workbook->year, $imported, true)) {
            $state->reject(ReasonCode::YEAR_ALREADY_IMPORTED, null, null);
        }
        if ([] !== array_filter($imported, static fn (int $year): bool => $year > $workbook->year)) {
            $state->reject(ReasonCode::LATER_YEAR_ALREADY_IMPORTED, null, null);
        }

        if (!$this->isReplay($state)) {
            $this->planMonths($workbook, $state, $notStarted);
        }

        return new ImportPlan(
            $workbook->year,
            $state->steps(),
            $state->instrumentsToCreate(),
            $state->existingInstruments(),
            $state->issues(),
            [...$workbook->warnings, ...$state->warnings()],
            $workbook->ignoredSheets,
            $state->ignoredLines(),
        );
    }

    private function isReplay(PlanningState $state): bool
    {
        foreach ($state->issues() as $issue) {
            if (ReasonCode::YEAR_ALREADY_IMPORTED === $issue->code || ReasonCode::LATER_YEAR_ALREADY_IMPORTED === $issue->code) {
                return true;
            }
        }

        return false;
    }

    /**
     * The reading issues, minus those of the months that have not started: such a month is left out whole and cannot reject the year.
     *
     * @param list<ParsedMonth> $notStarted
     *
     * @return list<Issue>
     */
    private static function issuesOutside(ParsedWorkbook $workbook, array $notStarted): array
    {
        if ([] === $notStarted) {
            return $workbook->issues;
        }
        $months = array_map(static fn (ParsedMonth $month): int => $month->month, $notStarted);
        $skipped = array_map(static fn (ParsedMonth $month): string => $month->sheet, $notStarted);
        foreach (array_filter([$workbook->pea, $workbook->ctoTr]) as $portfolio) {
            foreach ($portfolio->rows as $row) {
                if (null !== $row->month && in_array($row->month, $months, true)) {
                    $skipped[] = $portfolio->sheet.'!'.$row->row;
                }
            }
        }

        return array_values(array_filter($workbook->issues, static function (Issue $issue) use ($skipped): bool {
            $row = null === $issue->cell || 1 !== preg_match('/([0-9]+)$/', $issue->cell, $matches) ? null : $matches[1];

            return !in_array($issue->sheet, $skipped, true) && !in_array($issue->sheet.'!'.$row, $skipped, true);
        }));
    }

    /**
     * @param list<ParsedMonth> $notStarted
     */
    private function planMonths(ParsedWorkbook $workbook, PlanningState $state, array $notStarted): void
    {
        $lines = new LinePlanner($state);
        $savings = new SavingsPlanner($state);
        $accounts = new AccountTablePlanner($state);
        $portfolio = new PortfolioPlanner($state, array_values(array_filter([$workbook->pea, $workbook->ctoTr])));

        $first = true;
        foreach ($workbook->months as $month) {
            if (in_array($month, $notStarted, true)) {
                $state->reject(ReasonCode::MONTH_NOT_ENDED, $month->sheet, null);
                continue;
            }
            if ($first) {
                $accounts->opening($month);
                $portfolio->opening($month->month);
                $first = false;
            }
            $lines->income($month);
            $lines->expensesAndRefunds($month);
            $savings->transfersAndDeposits($month);
            $portfolio->buys($month);
            $accounts->balances($month);
            $portfolio->valuations($month);
        }
    }
}
