<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Positions;

use App\Module\Foundation\Domain\CalculatedDecimal;

final readonly class Position
{
    /**
     * @param list<OpenLot> $openLots
     * @param list<string>  $orderDependentDates
     */
    public function __construct(
        public string $instrumentId,
        public CalculatedDecimal $quantity,
        public ?CalculatedDecimal $fifoCostBasis,
        public ?CalculatedDecimal $averageCostBasis,
        public ?CalculatedDecimal $averageUnitCost,
        public ?string $costReason,
        public ?\DateTimeImmutable $firstAcquiredOn,
        public ?\DateTimeImmutable $lastOperationOn,
        public array $openLots,
        public array $orderDependentDates,
    ) {
    }
}
