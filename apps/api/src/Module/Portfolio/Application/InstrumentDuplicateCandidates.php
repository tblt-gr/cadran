<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final class InstrumentDuplicateCandidates extends \RuntimeException
{
    /** @param list<InstrumentCandidateView> $candidates */
    public function __construct(public readonly array $candidates)
    {
        parent::__construct('Similar instruments already exist.');
    }
}
