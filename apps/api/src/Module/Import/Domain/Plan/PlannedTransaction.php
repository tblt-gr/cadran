<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Plan;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Import\Domain\SourceReference;

/**
 * An income, an expense or an opening adjustment: the only steps that are
 * transactions with a source reference. `amount` is signed as the account
 * books it; `analyticAxes` is null when the category's own defaults apply.
 */
final readonly class PlannedTransaction implements PlannedStep
{
    /**
     * @param list<string>|null $analyticAxes
     */
    public function __construct(
        private StepKind $kind,
        private int $month,
        private string $sheet,
        private string $cell,
        public string $accountId,
        public ?string $categoryId,
        public ?array $analyticAxes,
        public DecimalValue $amount,
        public string $bookedOn,
        public string $rawLabel,
        public string $note,
        public SourceReference $sourceRef,
    ) {
    }

    public function kind(): StepKind
    {
        return $this->kind;
    }

    public function month(): int
    {
        return $this->month;
    }

    public function sheet(): string
    {
        return $this->sheet;
    }

    public function cell(): string
    {
        return $this->cell;
    }

    /** The key a refund uses to name this expense. */
    public function key(): string
    {
        return $this->sourceRef->toString();
    }
}
