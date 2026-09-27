<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/** One row of the multi-year report: one year, its state and month mix, and one cell per column. */
final readonly class MultiYearYearView
{
    /** @param array<string, MultiYearCellView> $cells by column id */
    public function __construct(
        public int $year,
        public string $state,
        public int $countedMonths,
        public int $snapshotMonths,
        public int $liveMonths,
        public MultiYearMetricPolicyView $metricPolicy,
        public array $cells,
    ) {
    }
}
