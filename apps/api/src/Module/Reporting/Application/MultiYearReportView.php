<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MultiYearReportView
{
    /**
     * @param list<AnnualColumnView>                        $columns
     * @param list<MultiYearYearView>                       $years
     * @param array<string, MultiYearSummaryCellView>       $summary        by column id
     * @param array<string, MultiYearDetailedAggregateView> $detailedPeriod by column id
     */
    public function __construct(
        public int $from,
        public int $to,
        public ?int $availableFrom,
        public bool $inflationAdjusted,
        public string $incompleteMonths,
        public array $columns,
        public array $years,
        public array $summary,
        public string $quality,
        public array $detailedPeriod,
    ) {
    }
}
