<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Positions;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Portfolio\Domain\OperationType;

final readonly class LotMovement
{
    public function __construct(
        public string $operationId,
        public \DateTimeImmutable $tradeDate,
        public int $sequence,
        public OperationType $type,
        public CalculatedDecimal $quantityDelta,
        public ?CalculatedDecimal $costDelta,
        public string $lotId,
    ) {
    }
}
