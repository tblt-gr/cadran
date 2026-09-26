<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

final readonly class MonthlyMovement
{
    /** @param list<MonthlySplit> $splits */
    public function __construct(
        public DecimalValue $amount,
        public AssetCode $asset,
        public MonthlyMovementKind $kind,
        public array $splits,
        public bool $savingsDestination,
        public string $transactionId = '',
        public ?string $accountKind = null,
    ) {
    }
}
