<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

final class MonthlyLedgerCalculator
{
    public static function validTransfer(MonthlySavingsTransfer $pair): bool
    {
        return !$pair->voided
            && 'BOOKED' === $pair->sourceState
            && self::wellFormedTransfer($pair);
    }

    public static function wellFormedTransfer(MonthlySavingsTransfer $pair): bool
    {
        if ($pair->voided) {
            return true;
        }
        if (null === $pair->sourceTransactionId || null === $pair->targetTransactionId
            || null === $pair->sourceAccountId || null === $pair->targetAccountId
            || null === $pair->sourceAmount || null === $pair->targetAmount
            || null === $pair->sourceAsset || null === $pair->targetAsset
            || null === $pair->sourceState || $pair->sourceState !== $pair->targetState
            || !in_array($pair->sourceState, ['BOOKED', 'PENDING', 'VOIDED', 'REJECTED'], true)
            || null === $pair->sourceBookedOn || $pair->sourceBookedOn !== $pair->targetBookedOn
            || !$pair->sourceAsset->equals($pair->targetAsset)
            || !$pair->sourceAmount->isNegative() || $pair->targetAmount->isNegative()
        ) {
            return false;
        }

        return ExactDecimal::isZero(ExactDecimal::add($pair->sourceAmount, $pair->targetAmount));
    }

    /**
     * @param list<MonthlyLedgerEntry> $entries
     *
     * @return list<MonthlyLedgerSource>
     */
    public static function categorySources(
        array $entries,
        string $categoryType,
        string $categoryId,
        ?string $axis,
    ): array {
        $sources = [];
        foreach ($entries as $entry) {
            if (!self::matchesType($entry->kind, $categoryType)) {
                continue;
            }

            $selected = array_filter(
                $entry->splits,
                static fn (MonthlySplit $split): bool => $split->categoryId === $categoryId
                    && ('EXPENSE' !== $categoryType || null === $axis || in_array($axis, $split->analyticAxes, true)),
            );
            if ([] === $selected) {
                continue;
            }

            $amount = DecimalValue::zero();
            foreach ($selected as $split) {
                $amount = ExactDecimal::add($amount, $split->amount);
            }
            $sources[] = new MonthlyLedgerSource(
                $entry->id,
                $entry->id,
                $entry->bookedOn,
                $entry->label,
                $amount,
                $entry->asset,
            );
        }

        usort($sources, static fn (MonthlyLedgerSource $left, MonthlyLedgerSource $right): int => [
            $right->bookedOn->format('Y-m-d'),
            $right->id,
        ] <=> [
            $left->bookedOn->format('Y-m-d'),
            $left->id,
        ]);

        return $sources;
    }

    /** @param list<MonthlyLedgerSource> $sources */
    public static function total(array $sources, bool $invert): MonthlyLedgerTotal
    {
        if ([] === $sources) {
            return new MonthlyLedgerTotal(null, null, 'NO_MOVEMENTS', 0, false);
        }

        $assets = [];
        foreach ($sources as $source) {
            $assets[$source->asset->toString()] = $source->asset;
        }
        if (1 !== count($assets)) {
            return new MonthlyLedgerTotal(null, null, 'MIXED_ASSETS', count($sources), true);
        }

        $value = DecimalValue::zero();
        foreach ($sources as $source) {
            $value = ExactDecimal::add($value, $source->amount);
        }
        if ($invert) {
            $value = ExactDecimal::negate($value);
        }

        return new MonthlyLedgerTotal($value, array_values($assets)[0], null, count($sources), true);
    }

    private static function matchesType(MonthlyMovementKind $kind, string $categoryType): bool
    {
        return match ($categoryType) {
            'INCOME' => MonthlyMovementKind::INCOME === $kind,
            'EXPENSE' => MonthlyProjectionCalculator::contributesToBudgetExpenses($kind),
            default => false,
        };
    }
}
