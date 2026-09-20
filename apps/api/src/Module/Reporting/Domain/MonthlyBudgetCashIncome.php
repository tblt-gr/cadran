<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;

final readonly class MonthlyBudgetCashIncome
{
    public function __construct(
        public ?string $amount,
        public ?AssetCode $asset,
        public ?MonthlyProjectionReason $reason,
    ) {
        if ((null === $amount) === (null === $reason)) {
            throw new \InvalidArgumentException('A monthly budget cash income carries either an amount or a reason.');
        }
    }
}
