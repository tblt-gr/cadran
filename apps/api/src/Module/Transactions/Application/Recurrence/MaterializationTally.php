<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

/** Counts only: a materialisation never carries an amount or a label out of the use case. */
final class MaterializationTally
{
    public int $created = 0;
    public int $booked = 0;
    public int $skippedClosedPeriod = 0;
    public int $skippedRefused = 0;

    public function changedAnything(): bool
    {
        return $this->created > 0 || $this->booked > 0;
    }

    public function add(self $other): void
    {
        $this->created += $other->created;
        $this->booked += $other->booked;
        $this->skippedClosedPeriod += $other->skippedClosedPeriod;
        $this->skippedRefused += $other->skippedRefused;
    }
}
