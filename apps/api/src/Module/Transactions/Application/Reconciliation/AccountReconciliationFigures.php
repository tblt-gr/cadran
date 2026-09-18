<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Reconciliation;

use App\Module\Transactions\Domain\Reconciliation\AccountBalanceComparison;

/** A comparison with the first day its movements were actually summed from. */
final readonly class AccountReconciliationFigures
{
    public function __construct(
        public AccountBalanceComparison $comparison,
        public \DateTimeImmutable $windowStart,
    ) {
    }
}
