<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\DecimalValue;

final readonly class MonthlyPortfolioCostItem
{
    public function __construct(
        public string $accountId,
        public string $label,
        public string $assetCode,
        public DecimalValue $fees,
        public DecimalValue $taxes,
    ) {
    }
}
