<?php

declare(strict_types=1);

namespace App\Module\Demo\Application;

/**
 * A tiny linear congruential generator over integers only. It exists so the
 * demo ledger is identical on every run and every PHP build, which mt_rand
 * does not promise across versions.
 */
final class DemoRandom
{
    public function __construct(private int $state)
    {
    }

    /** Inclusive on both ends. */
    public function between(int $min, int $max): int
    {
        $this->state = ($this->state * 1103515245 + 12345) & 0x7FFFFFFF;

        return $min + ($this->state >> 8) % ($max - $min + 1);
    }
}
