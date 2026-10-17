<?php

declare(strict_types=1);

namespace App\Module\Import\Application\Planning;

use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Import\Application\ImportAccount;
use App\Module\Import\Domain\Block;
use App\Module\Import\Domain\Plan\PlannedBalance;
use App\Module\Import\Domain\Plan\PlannedTransaction;
use App\Module\Import\Domain\Plan\StepKind;
use App\Module\Import\Domain\ReasonCode;
use App\Module\Import\Domain\SourceReference;
use App\Module\Import\Domain\Workbook\AccountRow;
use App\Module\Import\Domain\Workbook\ParsedMonth;

/**
 * Plans the account table of a month: the opening adjustment of the first
 * month and the dated balance snapshots. Portfolio accounts are skipped here:
 * their values come from their own sheets.
 */
final class AccountTablePlanner
{
    public const string OPENING_LABEL = 'Opening balance (workbook import)';

    /** @var array<int, list<array{AccountRow, ImportAccount}>> */
    private array $resolved = [];

    public function __construct(private readonly PlanningState $state)
    {
    }

    /** A non-zero opening balance of an account with no history becomes one adjustment on the last day of the previous month. */
    public function opening(ParsedMonth $month): void
    {
        $day = PlanningState::lastDayBefore($this->state->year, $month->month);
        $firstOfMonth = new \DateTimeImmutable(sprintf('%04d-%02d-01', $this->state->year, $month->month), new \DateTimeZone('UTC'));

        foreach ($this->rows($month) as [$row, $account]) {
            $opening = $row->opening;
            if ('TRANSACTIONS' !== $account->valuationMode || null === $opening || ExactDecimal::isZero($opening)) {
                continue;
            }
            if ($this->state->references->hasTransactionBefore($account->id, $firstOfMonth)) {
                continue;
            }
            $cell = 'K'.$row->row;
            if (!$this->state->canBook($account, $day, $month->sheet, $cell)) {
                continue;
            }
            $this->state->emit(new PlannedTransaction(
                StepKind::OPENING_ADJUSTMENT, $month->month, $month->sheet, $cell, $account->id, null, null, $opening, $day, self::OPENING_LABEL,
                PlanningState::note($this->state->year, $month->sheet, $cell), new SourceReference($this->state->year, $month->month, Block::OPEN, $row->labelKey, 1),
            ), $day);
        }
    }

    public function balances(ParsedMonth $month): void
    {
        $day = $this->state->bookingDay($month->month);

        foreach ($this->rows($month) as [$row, $account]) {
            if ($account->isPortfolio() || null === $row->closing) {
                continue;
            }
            $cell = 'L'.$row->row;
            if (!$this->state->canBook($account, $day, $month->sheet, $cell)) {
                continue;
            }
            // A snapshot is not a transaction: the source reference travels in its comment, for traceability only.
            $reference = new SourceReference($this->state->year, $month->month, Block::ACC, $row->labelKey, 1);
            $this->state->emit(new PlannedBalance(
                $month->month, $month->sheet, $cell, $account->id, $row->closing, $day,
                PlanningState::note($this->state->year, $month->sheet, $cell).' ('.$reference->toString().')',
            ), $day);
        }
    }

    /**
     * The reading problems of K and L, raised only for a row that resolves to an account the planner uses. A portfolio
     * account takes its values from its own sheet. A snapshots account may carry a market value with more decimals
     * than the asset (rounded, with a warning); a transactions account may not.
     */
    private function raiseReadingProblems(ParsedMonth $month, AccountRow $row, ImportAccount $account): void
    {
        if ($account->isPortfolio()) {
            return;
        }
        $snapshots = 'SNAPSHOTS' === $account->valuationMode;
        foreach (['K' => [$row->openingProblem, $row->openingRounded], 'L' => [$row->closingProblem, $row->closingRounded]] as $column => [$problem, $rounded]) {
            $cell = $column.$row->row;
            if (null !== $problem) {
                $this->state->reject($problem, $month->sheet, $cell);
            } elseif ($rounded && $snapshots) {
                $this->state->warnValueRounded($month->sheet, $cell);
            } elseif ($rounded) {
                $this->state->reject(ReasonCode::PRECISION_EXCEEDED, $month->sheet, $cell);
            }
        }
    }

    /**
     * The rows of the table that resolve to one Cadran account each: a label
     * outside the mapping, or two rows landing on the same account, is unmapped.
     *
     * @return list<array{AccountRow, ImportAccount}>
     */
    private function rows(ParsedMonth $month): array
    {
        if (isset($this->resolved[$month->month])) {
            return $this->resolved[$month->month];
        }

        $rows = [];
        $seen = [];
        foreach ($month->accounts as $row) {
            if ($this->state->isIgnored($row->labelKey)) {
                continue;
            }
            $account = $this->state->account($this->state->mapping->accountFor($row->labelKey), $month->sheet, $row->labelCell, true);
            if (null === $account) {
                continue;
            }
            if (isset($seen[$account->id])) {
                $this->state->reject(ReasonCode::UNMAPPED_ACCOUNT, $month->sheet, $row->labelCell);
                continue;
            }
            $seen[$account->id] = true;
            $this->raiseReadingProblems($month, $row, $account);
            $rows[] = [$row, $account];
        }

        return $this->resolved[$month->month] = $rows;
    }
}
