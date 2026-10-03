<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

use App\Module\Foundation\Domain\DecimalValue;

final readonly class ValuationFigures
{
    /** @param list<ValuationLine> $lines */
    public function __construct(
        public DecimalValue $cash,
        public bool $cashObserved,
        public ?DecimalValue $securitiesValue,
        public ?DecimalValue $totalValue,
        public ValuationCompleteness $completeness,
        public array $lines,
    ) {
    }
}
