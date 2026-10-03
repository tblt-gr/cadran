<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Cash;

use App\Module\Foundation\Domain\DecimalValue;

final readonly class CashLedgerResult
{
    /**
     * @param list<CashMovement> $movements
     * @param list<string>       $negativeOperationIds operations after which the running balance is below zero
     */
    public function __construct(
        public DecimalValue $openingBalance,
        public DecimalValue $closingBalance,
        public CashTotals $totals,
        public array $movements,
        public array $negativeOperationIds,
        public ?\DateTimeImmutable $firstNegativeDate,
    ) {
    }
}
