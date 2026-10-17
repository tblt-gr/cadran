<?php

declare(strict_types=1);

namespace App\Module\Import\Application\Planning;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Import\Application\ImportAccount;
use App\Module\Import\Domain\Block;
use App\Module\Import\Domain\Plan\PlannedBuy;
use App\Module\Import\Domain\Plan\PlannedDeposit;
use App\Module\Import\Domain\Plan\PlannedValuation;
use App\Module\Import\Domain\ReasonCode;
use App\Module\Import\Domain\SourceReference;
use App\Module\Import\Domain\Workbook\ParsedMonth;
use App\Module\Import\Domain\Workbook\ParsedMovement;
use App\Module\Import\Domain\Workbook\ParsedPortfolioSheet;
use App\Module\Import\Domain\Workbook\PortfolioRow;

/**
 * Plans the PEA and CTO_TR sheets: the purchases and the monthly valuations of
 * each month, and the opening state when the first month sheet comes after the
 * months the portfolio sheet already holds. Deposits are not read from the
 * sheet: the savings transfer of the month produces them.
 */
final class PortfolioPlanner
{
    private const string UNIT_NOTE = 'Quantity unknown in the workbook: amount imported as quantity at unit price 1';

    /** @var array<string, ?ImportAccount> */
    private array $accounts = [];

    /**
     * @param list<ParsedPortfolioSheet> $sheets
     */
    public function __construct(private readonly PlanningState $state, private readonly array $sheets)
    {
    }

    public function buys(ParsedMonth $month): void
    {
        $day = $this->state->bookingDay($month->month);
        foreach ($this->sheets as $sheet) {
            $row = $sheet->rowOfMonth($month->month);
            foreach (null === $row ? [] : $row->buys as $movement) {
                $this->buy($sheet, $movement, $month->month, $day, $movement->referenceIn($this->state->year)->toString());
            }
        }
    }

    public function valuations(ParsedMonth $month): void
    {
        $day = $this->state->bookingDay($month->month);
        foreach ($this->sheets as $sheet) {
            $row = $sheet->rowOfMonth($month->month);
            if (null !== $row) {
                $this->valuation($sheet, $row, $month->month, $day);
            }
        }
    }

    /** The rows before the first month sheet, with the previous December, become one deposit, their purchases and one valuation. */
    public function opening(int $firstMonth): void
    {
        $day = PlanningState::lastDayBefore($this->state->year, $firstMonth);
        foreach ($this->sheets as $sheet) {
            $earlier = array_values(array_filter(
                $sheet->rows,
                static fn (PortfolioRow $row): bool => null !== $row->month && $row->month < $firstMonth && self::hasData($row),
            ));
            if ([] === $earlier) {
                continue;
            }
            $opening = $sheet->openingRow();
            $rows = null === $opening ? $earlier : [$opening, ...$earlier];
            if (null !== $opening) {
                $this->raiseDeferred($opening);
            }

            $this->openingDeposit($sheet, $rows, $firstMonth, $day);
            $counters = [];
            foreach ($rows as $row) {
                foreach ($row->buys as $movement) {
                    $counters[$movement->labelKey] = ($counters[$movement->labelKey] ?? 0) + 1;
                    $reference = new SourceReference($this->state->year, $firstMonth, Block::OPEN, self::openingKey($sheet, $movement->labelKey), $counters[$movement->labelKey]);
                    $this->buy($sheet, $movement, $firstMonth, $day, $reference->toString());
                }
            }
            $this->valuation($sheet, $earlier[array_key_last($earlier)], $firstMonth, $day, Block::OPEN);
        }
    }

    /** The opening row is read lazily: its reading problems count only now that the opening state is built from it. */
    private function raiseDeferred(PortfolioRow $opening): void
    {
        foreach ($opening->issues as $issue) {
            $this->state->reject($issue->code, $issue->sheet, $issue->cell, $issue->rejection);
        }
        foreach ($opening->warnings as $warning) {
            $this->state->warn($warning);
        }
    }

    /**
     * @param list<PortfolioRow> $rows
     */
    private function openingDeposit(ParsedPortfolioSheet $sheet, array $rows, int $firstMonth, string $day): void
    {
        $total = DecimalValue::zero();
        foreach ($rows as $row) {
            if (null !== $row->deposits) {
                $total = ExactDecimal::add($total, $row->deposits);
            }
        }
        if (ExactDecimal::isZero($total)) {
            return;
        }
        if ($total->isNegative()) {
            $this->state->reject(ReasonCode::FORMULA_NOT_SUPPORTED, $sheet->sheet, 'C'.$rows[0]->row);

            return;
        }
        $account = $this->account($sheet);
        if (null === $account || !$this->state->canBook($account, $day, $sheet->sheet, null)) {
            return;
        }
        $reference = new SourceReference($this->state->year, $firstMonth, Block::OPEN, self::openingKey($sheet, 'C'), 1);
        $this->state->emit(new PlannedDeposit($firstMonth, $sheet->sheet, null, $account->id, $total, $day, null, $reference->toString()), $day);
    }

    private function buy(ParsedPortfolioSheet $sheet, ParsedMovement $movement, int $month, string $day, string $reference): void
    {
        $account = $this->account($sheet);
        if (null === $account) {
            return;
        }
        if ($movement->amount->isNegative()) {
            // A negative purchase would be a sale or a correction, which the workbook does not describe.
            $this->state->reject(ReasonCode::FORMULA_NOT_SUPPORTED, $sheet->sheet, $movement->cell);

            return;
        }
        $instrument = $this->instrumentOf($sheet, $movement);
        if (null === $instrument) {
            return;
        }
        $split = $this->split($sheet, $movement, $month);
        if ($split instanceof ReasonCode) {
            $this->state->reject($split, $sheet->sheet, $movement->cell);

            return;
        }
        if (!$this->state->canBook($account, $day, $sheet->sheet, $movement->cell)) {
            return;
        }
        [$quantity, $price] = $split;
        $this->state->emit(new PlannedBuy(
            $month, $sheet->sheet, $movement->cell, $account->id, $instrument, $quantity, $price, $movement->amount, $day, $reference,
            self::statesQuantity($sheet, $movement) ? null : self::UNIT_NOTE,
        ), $day);
    }

    /**
     * @return array{DecimalValue, DecimalValue}|ReasonCode
     */
    private function split(ParsedPortfolioSheet $sheet, ParsedMovement $movement, int $month): array|ReasonCode
    {
        if (self::statesQuantity($sheet, $movement)) {
            return QuantityResolver::ofProduct($movement->factors, $this->state->mapping->peaQuantityFirst($this->state->year, $month), $movement->ordinal - 1);
        }

        return QuantityResolver::ofAmount($movement->amount);
    }

    /** Only a PEA product term states a quantity and a price; a CTO column holds the amount of a fixed-amount purchase. */
    private static function statesQuantity(ParsedPortfolioSheet $sheet, ParsedMovement $movement): bool
    {
        return Block::PEA === $sheet->kind && $movement->isProduct && 2 === count($movement->factors);
    }

    private function instrumentOf(ParsedPortfolioSheet $sheet, ParsedMovement $movement): ?string
    {
        $mapped = null;
        foreach ($sheet->instruments as $column) {
            if ($column->column === $movement->labelKey) {
                $mapped = $this->state->mapping->portfolio($sheet->kind)?->instrument($column->headerKey);
            }
        }
        // A missing instrument is created from the mapping, and the portfolio refuses one with no identifier.
        $unusable = null === $mapped || (null === $mapped->isin && null === $mapped->ticker && null === $this->state->references->instrument($mapped->name));
        if (null === $mapped || $unusable) {
            $this->state->reject(ReasonCode::UNMAPPED_LABEL, $sheet->sheet, $movement->labelKey.'2');

            return null;
        }

        return $this->state->useInstrument($mapped);
    }

    private function valuation(ParsedPortfolioSheet $sheet, PortfolioRow $row, int $month, string $day, ?Block $block = null): void
    {
        $securities = $row->securities;
        if (null === $securities) {
            return;
        }
        $securitiesColumn = Block::PEA === $sheet->kind ? 'E' : 'H';
        if (null === $row->cash) {
            // The total includes the cash: without it nothing exact can be said, and zero would be a lie.
            $this->state->reject(ReasonCode::LABEL_WITHOUT_AMOUNT, $sheet->sheet, 'B'.$row->row);

            return;
        }
        $account = $this->account($sheet);
        $cell = $securitiesColumn.$row->row;
        if (null === $account || !$this->state->canBook($account, $day, $sheet->sheet, $cell)) {
            return;
        }
        // A valuation is not a transaction: the source reference travels in its note, for traceability only.
        $reference = new SourceReference($this->state->year, $month, $block ?? $sheet->kind, null === $block ? $securitiesColumn : self::openingKey($sheet, $securitiesColumn), 1);
        $this->state->emit(new PlannedValuation(
            $month, $sheet->sheet, $cell, $account->id, ExactDecimal::add($securities, $row->cash), $row->cash, $day,
            PlanningState::note($this->state->year, $sheet->sheet, $cell).' ('.$reference->toString().')',
        ), $day);
    }

    /** The portfolio account of a sheet, resolved once; it must be followed in portfolio mode. */
    private function account(ParsedPortfolioSheet $sheet): ?ImportAccount
    {
        $key = $sheet->kind->value;
        if (array_key_exists($key, $this->accounts)) {
            return $this->accounts[$key];
        }
        $account = $this->state->account($this->state->mapping->portfolio($sheet->kind)?->account, $sheet->sheet, null, true);
        if (null !== $account && !$account->isPortfolio()) {
            $this->state->reject(ReasonCode::UNMAPPED_ACCOUNT, $sheet->sheet, null);
            $account = null;
        }

        return $this->accounts[$key] = $account;
    }

    /** The opening records of the PEA and CTO_TR sheets share one block, so their column letters are qualified by the sheet. */
    private static function openingKey(ParsedPortfolioSheet $sheet, string $column): string
    {
        return (Block::PEA === $sheet->kind ? 'pea' : 'cto-tr').'-'.strtolower($column);
    }

    private static function hasData(PortfolioRow $row): bool
    {
        return null !== $row->cash || null !== $row->deposits || null !== $row->securities || [] !== $row->buys;
    }
}
