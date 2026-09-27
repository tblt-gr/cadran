<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/** The "average of complete years" row for one column. */
final readonly class MultiYearSummaryCellView
{
    public function __construct(
        public ?string $average,
        public ?string $averageReason,
        public ?MultiYearExtremeView $minimum,
        public ?MultiYearExtremeView $maximum,
    ) {
    }
}
