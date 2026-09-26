<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

/** Totals and statistics of one column over a range of months; a figure is null with its reason, never zero. */
final readonly class Aggregate
{
    /**
     * @param list<CalendarMonth> $countedMonths  statistics population
     * @param list<CalendarMonth> $totalMonths    totals population
     * @param list<ExcludedMonth> $excludedMonths
     */
    public function __construct(
        public ColumnKind $kind,
        public ?DecimalValue $total,
        public ?AggregateReason $totalReason,
        public ?DecimalValue $average,
        public ?AggregateReason $averageReason,
        public ?DecimalValue $median,
        public ?AggregateReason $medianReason,
        public ?MonthExtreme $minimum,
        public ?MonthExtreme $maximum,
        public ?AggregateReason $extremesReason,
        public ?MonthValue $periodEnd,
        public ?AssetCode $asset,
        public ?int $policyVersion,
        public array $countedMonths,
        public array $totalMonths,
        public array $excludedMonths,
        public IncompleteMonths $incompleteMonths,
        public AggregateQuality $quality,
        public string $formula,
    ) {
    }
}
