<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;

final readonly class MonthlyBudgetActualSource
{
    public function __construct(
        public string $transactionId,
        public string $amount,
        public AssetCode $asset,
    ) {
    }
}
