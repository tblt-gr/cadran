<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

/** The numerator and denominator months of a rate column, over the same months. */
final readonly class RateInputs
{
    /**
     * @param list<MonthValue> $numerator
     * @param list<MonthValue> $denominator
     */
    public function __construct(public array $numerator, public array $denominator)
    {
    }
}
