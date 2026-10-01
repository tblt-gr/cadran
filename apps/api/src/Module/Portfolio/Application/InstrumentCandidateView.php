<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Portfolio\Domain\Instrument;

final readonly class InstrumentCandidateView
{
    public function __construct(public string $id, public string $name, public ?string $isin, public ?string $ticker, public string $status)
    {
    }

    public static function fromInstrument(Instrument $instrument): self
    {
        return new self($instrument->id, $instrument->name, $instrument->isin?->toString(), $instrument->ticker?->toString(), $instrument->status->value);
    }
}
