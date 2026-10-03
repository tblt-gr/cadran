<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Cash;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\OperationType;

final readonly class CashMovement
{
    public function __construct(
        public string $operationId,
        public \DateTimeImmutable $tradeDate,
        public int $sequence,
        public OperationType $type,
        public DecimalValue $cashAmount,
        public DecimalValue $runningBalance,
    ) {
    }
}
