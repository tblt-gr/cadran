<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Portfolio\Domain\InstrumentRepository;

final readonly class ReadInstrument
{
    public function __construct(private CallerWorkspace $caller, private InstrumentRepository $instruments)
    {
    }

    public function __invoke(string $id): InstrumentView
    {
        $instrument = $this->instruments->find($this->caller->resolve(), $id) ?? throw new InstrumentNotFound();

        return InstrumentView::fromInstrument($instrument);
    }
}
