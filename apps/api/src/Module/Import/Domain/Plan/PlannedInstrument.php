<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Plan;

use App\Module\Import\Domain\Mapping\InstrumentKind;

/** An instrument the workspace lacks and the executor creates before the first purchase. */
final readonly class PlannedInstrument
{
    public function __construct(
        public string $name,
        public InstrumentKind $type,
        public ?string $isin,
        public ?string $ticker,
    ) {
    }
}
