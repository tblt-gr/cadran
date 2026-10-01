<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Portfolio\Domain\InstrumentStatus;

final readonly class DeactivateInstrument
{
    public function __construct(private ChangeInstrumentStatus $change)
    {
    }

    public function __invoke(string $id, int $version): InstrumentView
    {
        return ($this->change)($id, $version, InstrumentStatus::INACTIVE);
    }
}
