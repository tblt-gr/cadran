<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

/** The catalogue of performance methods, each with the quality its formula gives before any warning lowers it. */
enum PerformanceMethod: string
{
    /** Bumped whenever a performance formula, constant or rounding changes; documented with the calculation pages. */
    public const string VERSION = 'PERF-1';

    case TOTAL_GAIN = 'TOTAL_GAIN';
    case MODIFIED_DIETZ = 'MODIFIED_DIETZ';
    case TWR = 'TWR';
    case MWR = 'MWR';

    public function baseQuality(): PerformanceQuality
    {
        return match ($this) {
            self::MODIFIED_DIETZ => PerformanceQuality::APPROXIMATION,
            default => PerformanceQuality::EXACT,
        };
    }
}
