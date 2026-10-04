<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Portfolio\Domain\Performance\PerformanceFinding;
use App\Module\Portfolio\Domain\Performance\PeriodAnchor;
use App\Module\Portfolio\Domain\PortfolioOperation;

/** What the builder resolved for a requested period: its anchors, the operations between them and the reasons met on the way. */
final readonly class PerformancePeriodData
{
    /**
     * @param list<PerformanceFinding> $blocking
     * @param list<PerformanceFinding> $warnings
     * @param list<PortfolioOperation> $operations active operations with start date < trade date <= end date, in replay order
     * @param list<PortfolioOperation> $history    every active operation up to the end date, the input of the position replay
     */
    public function __construct(
        public ?PeriodAnchor $start,
        public ?PeriodAnchor $end,
        public array $blocking,
        public array $warnings,
        public array $operations,
        public array $history,
        public int $displayPrecision,
    ) {
    }

    public function isCalculable(): bool
    {
        return [] === $this->blocking && null !== $this->start && null !== $this->end;
    }
}
