<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/**
 * The whole selected detailed period, one column, computed by the same exact engine as one year
 * ({@see \App\Module\Reporting\Domain\Aggregation\ReportAggregator}) over every month of every
 * year of the range. `total` is null with `NOT_ADDITIVE` for a stock column, which has no sum.
 */
final readonly class MultiYearDetailedAggregateView
{
    public function __construct(
        public ?string $total,
        public ?string $totalReason,
        public ?string $average,
        public ?string $averageReason,
        public ?string $median,
        public ?string $medianReason,
        public ?MultiYearDetailedExtremeView $minimum,
        public ?MultiYearDetailedExtremeView $maximum,
        public ?string $extremesReason,
        public string $quality,
    ) {
    }
}
