<?php

declare(strict_types=1);

namespace App\Module\Import\Application\Planning;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Import\Application\ImportCategory;
use App\Module\Import\Domain\Block;
use App\Module\Import\Domain\Plan\PlannedRefund;
use App\Module\Import\Domain\Plan\PlannedTransaction;
use App\Module\Import\Domain\Plan\StepKind;
use App\Module\Import\Domain\ReasonCode;
use App\Module\Import\Domain\Workbook\MovementLine;
use App\Module\Import\Domain\Workbook\ParsedMonth;
use App\Module\Import\Domain\Workbook\ParsedMovement;

/**
 * Plans the income, fixed and variable blocks of a month: one transaction per
 * movement, and each negative expense term as a refund of the largest expense
 * of the same category booked the same month.
 */
final class LinePlanner
{
    public function __construct(private readonly PlanningState $state)
    {
    }

    public function income(ParsedMonth $month): void
    {
        foreach ($month->linesOf(Block::INC) as $line) {
            if (null !== $this->state->mapping->lineFor(Block::INC, $line->labelKey)?->transferFrom) {
                continue; // planned by SavingsPlanner: a transfer, not income
            }
            $target = $this->resolve($month, $line, Block::INC, 'INCOME');
            if (null === $target) {
                continue;
            }
            $day = $this->state->bookingDay($month->month);
            foreach ($line->movements as $movement) {
                if ($movement->amount->isNegative()) {
                    $this->state->reject(ReasonCode::NEGATIVE_INCOME, $month->sheet, $line->amountCell);
                    continue;
                }
                $this->book($month, $movement, $target, $day, $movement->amount, StepKind::INCOME);
            }
        }
    }

    /** Fixed then variable expenses, then the refunds they left pending. */
    public function expensesAndRefunds(ParsedMonth $month): void
    {
        $day = $this->state->bookingDay($month->month);
        $candidates = [];
        $refunds = [];

        foreach ([Block::FIX, Block::VAR] as $block) {
            foreach ($month->linesOf($block) as $line) {
                $target = $this->resolve($month, $line, $block, 'EXPENSE');
                if (null === $target) {
                    continue;
                }
                foreach ($line->movements as $movement) {
                    if ($movement->amount->isNegative()) {
                        $refunds[] = [$line, $movement, $target];
                        continue;
                    }
                    $step = $this->book($month, $movement, $target, $day, ExactDecimal::negate($movement->amount), StepKind::EXPENSE);
                    if (null !== $step) {
                        $candidates[] = new RefundCandidate($step->key(), $target->category->id, $block->value.$line->row, $movement->amount);
                    }
                }
            }
        }

        foreach ($refunds as [$line, $movement, $target]) {
            $this->refund($month, $line, $movement, $target, $day, $candidates);
        }
    }

    /**
     * @param list<RefundCandidate> $candidates
     */
    private function refund(ParsedMonth $month, MovementLine $line, ParsedMovement $movement, LineTarget $target, string $day, array $candidates): void
    {
        $magnitude = ExactDecimal::absolute($movement->amount);
        $original = $this->largestRefundable($candidates, $target->category->id, $line->block->value.$line->row, $magnitude);
        if (null === $original) {
            $this->state->reject(ReasonCode::UNLINKABLE_REFUND, $month->sheet, $line->amountCell);

            return;
        }
        if (!$this->state->canBook($target->account, $day, $month->sheet, $line->amountCell)) {
            return;
        }
        $original->consume($magnitude);

        $this->state->emit(new PlannedRefund(
            $month->month, $month->sheet, $line->amountCell, $target->account->id, $original->key, $magnitude, $day, PlanningState::label($line->rawLabel),
            PlanningState::note($this->state->year, $month->sheet, $line->amountCell).' ('.$movement->referenceIn($this->state->year)->toString().')',
        ), $day);
    }

    /**
     * Same line first, then any line of the category; the largest original wins and the earliest on a tie.
     *
     * @param list<RefundCandidate> $candidates
     */
    private function largestRefundable(array $candidates, string $categoryId, string $lineId, DecimalValue $magnitude): ?RefundCandidate
    {
        foreach ([true, false] as $sameLineOnly) {
            $best = null;
            foreach ($candidates as $candidate) {
                if ($candidate->categoryId !== $categoryId || ($sameLineOnly && $candidate->lineId !== $lineId) || !$candidate->canAbsorb($magnitude)) {
                    continue;
                }
                if (null === $best || $candidate->original->compareTo($best->original) > 0) {
                    $best = $candidate;
                }
            }
            if (null !== $best) {
                return $best;
            }
        }

        return null;
    }

    private function book(ParsedMonth $month, ParsedMovement $movement, LineTarget $target, string $day, DecimalValue $amount, StepKind $kind): ?PlannedTransaction
    {
        if (!$this->state->canBook($target->account, $day, $month->sheet, $movement->cell)) {
            return null;
        }
        $step = new PlannedTransaction(
            $kind, $month->month, $month->sheet, $movement->cell, $target->account->id, $target->category->id, $target->axes, $amount, $day,
            PlanningState::label($movement->rawLabel), PlanningState::note($this->state->year, $month->sheet, $movement->cell), $movement->referenceIn($this->state->year),
        );
        $this->state->emit($step, $day);

        return $step;
    }

    private function resolve(ParsedMonth $month, MovementLine $line, Block $block, string $categoryType): ?LineTarget
    {
        if ([] === $line->movements) {
            return null;
        }
        if ($this->state->isIgnored($line->labelKey)) {
            return null;
        }
        $sheet = $month->sheet;
        $mapped = $this->state->mapping->lineFor($block, $line->labelKey);
        $category = null === $mapped?->category ? null : $this->state->references->category($mapped->category);
        if (null === $mapped || null === $category || $category->type !== $categoryType) {
            $this->state->reject(ReasonCode::UNMAPPED_LABEL, $sheet, $line->labelCell);

            return null;
        }

        $account = null === $mapped->account
            ? $this->state->mainAccount()
            : $this->state->account($mapped->account, $sheet, $line->labelCell, false);
        if (null === $account) {
            return null;
        }

        return new LineTarget($account, $category, self::axes($block, $category));
    }

    /**
     * @return list<string>|null the block of origin, unless the category already carries its own axes
     */
    private static function axes(Block $block, ImportCategory $category): ?array
    {
        if ($category->hasDefaultAxes) {
            return null;
        }

        return match ($block) {
            Block::FIX => ['FIXED'],
            Block::VAR => ['VARIABLE'],
            default => null,
        };
    }
}
