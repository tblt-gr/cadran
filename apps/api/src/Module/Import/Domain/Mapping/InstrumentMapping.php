<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Mapping;

/** The Cadran instrument to use, or to create, for a portfolio column header. */
final readonly class InstrumentMapping
{
    public function __construct(
        public string $name,
        public InstrumentKind $type,
        public ?string $isin,
        public ?string $ticker,
    ) {
    }
}
