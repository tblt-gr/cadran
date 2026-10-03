<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Positions;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Portfolio\Domain\OperationType;

final readonly class OpenLot
{
    public function __construct(
        public string $lotId,
        public string $originOperationId,
        public OperationType $originType,
        public \DateTimeImmutable $acquiredOn,
        public int $originSequence,
        public CalculatedDecimal $originalQuantity,
        public CalculatedDecimal $openQuantity,
        public ?CalculatedDecimal $costBasis,
    ) {
    }
}
