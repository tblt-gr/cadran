<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

/** A monotonic reading used to bound pattern matching time; never wall-clock time. */
interface ElapsedTime
{
    public function nanoseconds(): int;
}
