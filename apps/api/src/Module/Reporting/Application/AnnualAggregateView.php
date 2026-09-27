<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Reporting\Domain\Aggregation\Aggregate;
use App\Module\Reporting\Domain\Aggregation\MonthExtreme;

final readonly class AnnualAggregateView
{
    /**
     * @param list<string>                               $countedMonths
     * @param list<array{month: string, reason: string}> $excludedMonths
     */
    public function __construct(
        public string $kind,
        public ?string $total,
        public ?string $totalReason,
        public ?string $average,
        public ?string $averageReason,
        public ?string $median,
        public ?string $medianReason,
        public ?AnnualExtremeView $minimum,
        public ?AnnualExtremeView $maximum,
        public ?string $extremesReason,
        public ?AnnualExtremeView $periodEnd,
        public ?string $previousYearAverage,
        public ?string $previousYearAverageReason,
        public array $countedMonths,
        public array $excludedMonths,
        public string $quality,
        public string $formula,
    ) {
    }

    public static function of(Aggregate $aggregate, ?string $previousAverage, ?string $previousReason): self
    {
        $extreme = static fn (?MonthExtreme $extreme): ?AnnualExtremeView => null === $extreme
            ? null
            : new AnnualExtremeView($extreme->value->toString(), $extreme->month->key());

        return new self(
            $aggregate->kind->value,
            $aggregate->total?->toString(),
            $aggregate->totalReason?->value,
            $aggregate->average?->toString(),
            $aggregate->averageReason?->value,
            $aggregate->median?->toString(),
            $aggregate->medianReason?->value,
            $extreme($aggregate->minimum),
            $extreme($aggregate->maximum),
            $aggregate->extremesReason?->value,
            null === $aggregate->periodEnd?->value ? null : new AnnualExtremeView($aggregate->periodEnd->value->toString(), $aggregate->periodEnd->month->key()),
            $previousAverage,
            $previousReason,
            array_map(static fn ($month): string => $month->key(), $aggregate->countedMonths),
            array_map(static fn ($excluded): array => ['month' => $excluded->month->key(), 'reason' => $excluded->reason->value], $aggregate->excludedMonths),
            $aggregate->quality->value,
            $aggregate->formula,
        );
    }
}
