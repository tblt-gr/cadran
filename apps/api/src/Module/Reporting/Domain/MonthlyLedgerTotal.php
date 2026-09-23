<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

final readonly class MonthlyLedgerTotal
{
    public function __construct(
        public ?DecimalValue $value,
        public ?AssetCode $asset,
        public ?string $reason,
        public int $movementCount,
        public bool $hasMovements,
    ) {
    }
}
