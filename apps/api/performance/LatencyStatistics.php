<?php

declare(strict_types=1);

namespace App\Tests\Performance;

/**
 * Nearest-rank percentiles of a set of timings in milliseconds: the value at
 * rank ceil(p/100 x n) of the sorted samples. It is always one of the measured
 * values, so a slow outlier is never averaged away, which interpolation would do.
 */
final readonly class LatencyStatistics
{
    /** @var list<float> ascending */
    private array $sorted;

    /** @param list<float> $samplesMs */
    public function __construct(array $samplesMs)
    {
        if ([] === $samplesMs) {
            throw new \InvalidArgumentException('Percentiles need at least one sample.');
        }
        sort($samplesMs);
        $this->sorted = $samplesMs;
    }

    public function percentile(int $percent): float
    {
        if ($percent < 1 || $percent > 100) {
            throw new \InvalidArgumentException('A percentile is an integer from 1 to 100.');
        }
        $rank = (int) ceil($percent * count($this->sorted) / 100);

        return $this->sorted[$rank - 1];
    }
}
