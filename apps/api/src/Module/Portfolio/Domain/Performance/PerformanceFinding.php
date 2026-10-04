<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

/** A reason with the dates (month-ends or operation dates) it concerns, bounded for the response. */
final readonly class PerformanceFinding
{
    public const int MAX_DATES = 20;

    /** @var list<string> */
    public array $dates;

    /** @param list<string> $dates */
    public function __construct(public PerformanceReason $code, array $dates = [])
    {
        $unique = array_values(array_unique($dates));
        sort($unique);
        $this->dates = array_slice($unique, 0, self::MAX_DATES);
    }
}
