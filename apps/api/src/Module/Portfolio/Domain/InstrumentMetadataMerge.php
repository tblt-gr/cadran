<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

final readonly class InstrumentMetadataMerge
{
    /** @param list<InstrumentMetadataConflict> $conflicts */
    public function __construct(public Instrument $instrument, public array $conflicts)
    {
    }
}
