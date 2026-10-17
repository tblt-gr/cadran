<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Workbook;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Import\Domain\Block;
use App\Module\Import\Domain\SourceReference;

/**
 * One movement read from the workbook: one top-level term of an amount cell.
 *
 * `amount` is signed, at the asset precision (a negative amount is a refund in
 * FIX and VAR, a reverse transfer in SAV; income never carries one). `labelKey`
 * is the normalized label, or the column letter for a portfolio column, and
 * `ordinal` the 1-based position of the movement inside that label for that
 * month (term order, then row order). For a pure product term `q*p`, `isProduct`
 * is true and `factors` holds both factors as written; otherwise `factors` is
 * the amount alone.
 *
 * `month` is null only for the opening row of a portfolio sheet (the previous
 * December), whose records the planner dates and references itself.
 */
final readonly class ParsedMovement
{
    /**
     * @param list<DecimalValue> $factors
     */
    public function __construct(
        public Block $block,
        public string $rawLabel,
        public string $labelKey,
        public ?int $month,
        public string $sheet,
        public string $cell,
        public int $ordinal,
        public DecimalValue $amount,
        public bool $isProduct,
        public array $factors,
    ) {
    }

    /**
     * The source reference of the record this movement becomes. The planner
     * passes the month or block of an opening record when they differ.
     */
    public function referenceIn(int $year, ?int $month = null, ?Block $block = null): SourceReference
    {
        $resolvedMonth = $month ?? $this->month;
        if (null === $resolvedMonth) {
            throw new \LogicException('An opening-row movement needs the month of its opening record.');
        }

        return new SourceReference($year, $resolvedMonth, $block ?? $this->block, $this->labelKey, $this->ordinal);
    }
}
