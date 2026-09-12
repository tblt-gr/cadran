<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application\Double;

use App\Module\Transactions\Application\Categorization\ElapsedTime;

/** Every reading advances by a fixed step, so each timed evaluation costs exactly that step. */
final class SteppingElapsedTime implements ElapsedTime
{
    private int $now = 0;
    public int $readings = 0;

    public function __construct(private readonly int $stepNanoseconds)
    {
    }

    public function nanoseconds(): int
    {
        ++$this->readings;
        $reading = $this->now;
        $this->now += $this->stepNanoseconds;

        return $reading;
    }
}
