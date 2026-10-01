<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final readonly class InstrumentPage
{
    /** @param list<InstrumentView> $items */
    public function __construct(public array $items, public int $page, public int $perPage, public int $total)
    {
    }
}
