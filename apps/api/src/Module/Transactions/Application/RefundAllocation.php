<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Transactions\Domain\Transaction;
use Brick\Math\BigInteger;

/**
 * Proposes a refund allocation with integer units and exact remainders. This
 * intentionally does not use ExactDecimal::divide(): no rounded quotient may
 * decide which category receives the last display unit.
 */
final class RefundAllocation
{
    /** @return list<array{categoryId: string, amount: AssetAmount}> */
    public function propose(Transaction $original, AssetAmount $refund, int $displayPrecision): array
    {
        if ([] === $original->splits) {
            return [];
        }
        $scale = max($displayPrecision, $refund->value->scale());
        $wideScale = max(
            $scale,
            $original->amount->value->scale(),
            ...array_map(static fn ($split): int => $split->amount->value->scale(), $original->splits),
        );
        $total = self::units(ExactDecimal::absolute($original->amount->value), $wideScale);
        $refundUnits = self::units($refund->value, $scale);
        /** @var list<RefundAllocationRow> $rows */
        $rows = [];
        $floors = BigInteger::zero();
        foreach ($original->splits as $index => $split) {
            $numerator = $refundUnits->multipliedBy(self::units(ExactDecimal::absolute($split->amount->value), $wideScale));
            [$floor, $remainder] = $numerator->quotientAndRemainder($total);
            $floors = $floors->plus($floor);
            $rows[] = new RefundAllocationRow($index, $split->categoryId, $floor, $remainder);
        }
        $left = $refundUnits->minus($floors)->toInt();
        usort($rows, static fn (RefundAllocationRow $leftRow, RefundAllocationRow $rightRow): int => ($rightRow->remainder->compareTo($leftRow->remainder) ?: $leftRow->index <=> $rightRow->index));
        for ($index = 0; $index < $left; ++$index) {
            $rows[$index]->units = $rows[$index]->units->plus(1);
        }
        usort($rows, static fn (RefundAllocationRow $leftRow, RefundAllocationRow $rightRow): int => $leftRow->index <=> $rightRow->index);

        return array_map(static fn (RefundAllocationRow $row): array => [
            'categoryId' => $row->categoryId,
            'amount' => new AssetAmount(DecimalValue::fromString(self::decimal($row->units, $scale)), $refund->asset),
        ], array_values(array_filter($rows, static fn (RefundAllocationRow $row): bool => !$row->units->isZero())));
    }

    private static function units(DecimalValue $value, int $scale): BigInteger
    {
        $literal = ltrim($value->toString(), '-');
        [$integer, $fraction] = array_pad(explode('.', $literal, 2), 2, '');
        if (strlen($fraction) > $scale) {
            throw new \LogicException('A refund allocation amount exceeds the working scale.');
        }

        return BigInteger::of($integer.str_pad($fraction, $scale, '0'));
    }

    private static function decimal(BigInteger $units, int $scale): string
    {
        $literal = (string) $units;
        if (0 === $scale) {
            return $literal;
        }
        $literal = str_pad($literal, $scale + 1, '0', STR_PAD_LEFT);

        return substr($literal, 0, -$scale).'.'.substr($literal, -$scale);
    }
}
