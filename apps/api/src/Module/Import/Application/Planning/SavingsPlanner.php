<?php

declare(strict_types=1);

namespace App\Module\Import\Application\Planning;

use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Import\Application\ImportAccount;
use App\Module\Import\Domain\Block;
use App\Module\Import\Domain\Plan\PlannedDeposit;
use App\Module\Import\Domain\Plan\PlannedTransfer;
use App\Module\Import\Domain\ReasonCode;
use App\Module\Import\Domain\Workbook\MovementLine;
use App\Module\Import\Domain\Workbook\ParsedMonth;
use App\Module\Import\Domain\Workbook\ParsedMovement;

/**
 * Plans the savings block: first the income lines mapped as a transfer from an
 * account (money withdrawn from a savings account, written in the income block),
 * then each movement is a transfer between the main account
 * and the mapped account, and a transfer into a portfolio account is followed,
 * once every transfer of the month exists, by the deposit linked to it.
 */
final class SavingsPlanner
{
    public function __construct(private readonly PlanningState $state)
    {
    }

    public function transfersAndDeposits(ParsedMonth $month): void
    {
        $day = $this->state->bookingDay($month->month);
        /** @var list<PlannedDeposit> $deposits */
        $deposits = [];

        $this->incomeTransfers($month, $day);

        foreach ($month->linesOf(Block::SAV) as $line) {
            $accounts = $this->resolve($month, $line);
            if (null === $accounts) {
                continue;
            }
            [$main, $target] = $accounts;
            foreach ($line->movements as $movement) {
                $outgoing = !$movement->amount->isNegative();
                if (!$outgoing && $target->isPortfolio()) {
                    // Money leaving a portfolio account needs a withdrawal, which the workbook does not describe.
                    $this->state->reject(ReasonCode::FORMULA_NOT_SUPPORTED, $month->sheet, $line->amountCell);
                    continue;
                }
                $source = $outgoing ? $main : $target;
                $destination = $outgoing ? $target : $main;
                $transfer = $this->transfer($month, $line, $movement, $source, $destination, $day);
                if (null === $transfer) {
                    continue;
                }
                if ($outgoing && $target->isPortfolio()) {
                    $deposits[] = new PlannedDeposit($month->month, $month->sheet, $line->amountCell, $target->id, $transfer->amount, $day, $transfer->key, $transfer->key);
                }
            }
        }

        foreach ($deposits as $deposit) {
            $this->state->emit($deposit, $day);
        }
    }

    /** Each positive movement of an income line mapped with `transfer_from` is a transfer into its destination account. */
    private function incomeTransfers(ParsedMonth $month, string $day): void
    {
        foreach ($month->linesOf(Block::INC) as $line) {
            $mapped = $this->state->mapping->lineFor(Block::INC, $line->labelKey);
            if (null === $mapped?->transferFrom || [] === $line->movements || $this->state->isIgnored($line->labelKey)) {
                continue;
            }
            $source = $this->state->account($mapped->transferFrom, $month->sheet, $line->labelCell, false);
            $destination = null === $mapped->account
                ? $this->state->mainAccount()
                : $this->state->account($mapped->account, $month->sheet, $line->labelCell, false);
            if (null === $source || null === $destination) {
                continue;
            }
            if ($source->id === $destination->id) {
                $this->state->reject(ReasonCode::UNMAPPED_ACCOUNT, $month->sheet, $line->labelCell);
                continue;
            }
            foreach ($line->movements as $movement) {
                if ($movement->amount->isNegative()) {
                    $this->state->reject(ReasonCode::NEGATIVE_INCOME, $month->sheet, $line->amountCell);
                    continue;
                }
                $this->transfer($month, $line, $movement, $source, $destination, $day);
            }
        }
    }

    /** Plans one transfer of a movement, or null when an account cannot take a booking on that day. */
    private function transfer(ParsedMonth $month, MovementLine $line, ParsedMovement $movement, ImportAccount $source, ImportAccount $destination, string $day): ?PlannedTransfer
    {
        $booked = $this->state->canBook($source, $day, $month->sheet, $line->amountCell);
        $booked = $this->state->canBook($destination, $day, $month->sheet, $line->amountCell) && $booked;
        if (!$booked) {
            return null;
        }

        $reference = $movement->referenceIn($this->state->year)->toString();
        $transfer = new PlannedTransfer(
            $month->month, $month->sheet, $line->amountCell, $reference, $source->id, $destination->id, ExactDecimal::absolute($movement->amount), $day, PlanningState::label($line->rawLabel),
            PlanningState::note($this->state->year, $month->sheet, $line->amountCell).' ('.$reference.')',
        );
        $this->state->emit($transfer, $day);

        return $transfer;
    }

    /**
     * @return array{ImportAccount, ImportAccount}|null the main account and the target of the line
     */
    private function resolve(ParsedMonth $month, MovementLine $line): ?array
    {
        if ([] === $line->movements || $this->state->isIgnored($line->labelKey)) {
            return null;
        }
        $label = $this->state->mapping->savingsTarget($line->labelKey);
        if (null === $label) {
            $this->state->reject(ReasonCode::UNMAPPED_LABEL, $month->sheet, $line->labelCell);

            return null;
        }
        $target = $this->state->account($label, $month->sheet, $line->labelCell, true);
        $main = $this->state->mainAccount();
        if (null === $target || null === $main) {
            return null;
        }
        if ($target->id === $main->id) {
            $this->state->reject(ReasonCode::UNMAPPED_ACCOUNT, $month->sheet, $line->labelCell);

            return null;
        }

        return [$main, $target];
    }
}
