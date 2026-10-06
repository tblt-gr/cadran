<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Cash;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Portfolio\Domain\LegDirection;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;

/**
 * Portfolio cash as a pure function of the active operations in (trade date,
 * sequence) order: closing = opening + sum of cash amounts. It sums the stored
 * cash effect and never rounds.
 */
final class CashLedger
{
    /**
     * @param list<PortfolioOperation> $before active operations before the range, they only build the opening balance
     * @param list<PortfolioOperation> $range  active operations of the range
     *
     * @throws CashReplayFailed when a sum cannot be stored exactly
     */
    public static function replay(array $before, array $range): CashLedgerResult
    {
        try {
            return self::compute(self::ordered($before), self::ordered($range));
        } catch (PrecisionExceeded|MalformedDecimal $exception) {
            throw new CashReplayFailed('The cash figures are too large to be summed exactly.', previous: $exception);
        }
    }

    /**
     * @param list<PortfolioOperation> $before
     * @param list<PortfolioOperation> $range
     */
    private static function compute(array $before, array $range): CashLedgerResult
    {
        $opening = DecimalValue::zero();
        foreach ($before as $operation) {
            $opening = ExactDecimal::add($opening, $operation->cashAmount);
        }

        $zero = DecimalValue::zero();
        $totals = ['deposits' => $zero, 'withdrawals' => $zero, 'buys' => $zero, 'sells' => $zero, 'dividends' => $zero,
            'interest' => $zero, 'fees' => $zero, 'taxes' => $zero, 'lieu' => $zero, 'residuals' => $zero, 'supportsBought' => $zero, 'supportsSold' => $zero];
        $running = $opening;
        $movements = [];
        $negative = [];
        $firstNegative = null;
        foreach ($range as $operation) {
            $details = $operation->details;
            $running = ExactDecimal::add($running, $operation->cashAmount);
            $movements[] = new CashMovement($operation->id, $details->tradeDate, $operation->sequence, $details->type, $operation->cashAmount, $running);
            if ($running->isNegative()) {
                $negative[] = $operation->id;
                $firstNegative ??= $details->tradeDate;
            }
            $gross = $details->grossAmount ?? $zero;
            $key = match ($details->type) {
                OperationType::DEPOSIT, OperationType::PREMIUM => 'deposits',
                OperationType::WITHDRAWAL, OperationType::PARTIAL_SURRENDER, OperationType::FULL_SURRENDER => 'withdrawals',
                OperationType::BUY => 'buys',
                OperationType::SELL => 'sells',
                OperationType::DIVIDEND => 'dividends',
                OperationType::INTEREST => 'interest',
                OperationType::MERGER => 'lieu',
                default => null,
            };
            if (null !== $key) {
                $totals[$key] = ExactDecimal::add($totals[$key], $details->netPaidAmount() ?? $gross);
            }
            foreach ($details->legs as $leg) {
                $supportKey = LegDirection::IN === $leg->direction ? 'supportsBought' : 'supportsSold';
                $totals[$supportKey] = ExactDecimal::add($totals[$supportKey], $leg->amount);
                $totals['residuals'] = ExactDecimal::add($totals['residuals'], $leg->roundingResidual ?? $zero);
            }
            $totals['fees'] = ExactDecimal::add($totals['fees'], $details->fees ?? $zero);
            $totals['taxes'] = ExactDecimal::add($totals['taxes'], $details->taxes ?? $zero);
            if (null !== $operation->roundingResidual) {
                $totals['residuals'] = ExactDecimal::add($totals['residuals'], $operation->roundingResidual);
            }
        }

        return new CashLedgerResult(
            $opening, $running,
            new CashTotals(
                $totals['deposits'], $totals['withdrawals'], $totals['buys'], $totals['sells'], $totals['dividends'],
                $totals['interest'], $totals['fees'], $totals['taxes'], $totals['lieu'], $totals['residuals'], $totals['supportsBought'], $totals['supportsSold'],
            ),
            $movements, $negative, $firstNegative,
        );
    }

    /**
     * @param list<PortfolioOperation> $operations
     *
     * @return list<PortfolioOperation>
     */
    private static function ordered(array $operations): array
    {
        usort($operations, static fn (PortfolioOperation $a, PortfolioOperation $b): int => [$a->details->tradeDate, $a->sequence, $a->id]
            <=> [$b->details->tradeDate, $b->sequence, $b->id]);

        return $operations;
    }
}
