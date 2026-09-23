<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

final readonly class MonthlyLedgerEntry
{
    /** @param list<MonthlySplit> $splits */
    public function __construct(
        public string $id,
        public string $accountId,
        public DecimalValue $amount,
        public AssetCode $asset,
        public MonthlyMovementKind $kind,
        public \DateTimeImmutable $bookedOn,
        public string $label,
        public array $splits,
    ) {
    }
}
