<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\Valuation\Valuation;
use App\Module\Portfolio\Domain\Valuation\ValuationFlag;

final readonly class ValuationView
{
    /**
     * @param list<ValuationFlag>       $flags
     * @param array<string, Instrument> $instruments the instruments of the positions, by identifier
     */
    public function __construct(
        public Valuation $valuation,
        public array $flags,
        public array $instruments,
        public int $displayPrecision,
    ) {
    }
}
