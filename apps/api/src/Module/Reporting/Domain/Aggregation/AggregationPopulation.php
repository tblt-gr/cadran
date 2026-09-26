<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

use App\Module\Foundation\Domain\AssetCode;

/** Which months of a range enter the totals and the statistics of an aggregate. */
final readonly class AggregationPopulation
{
    /**
     * @param list<MonthValue>    $totals     complete and provisional months
     * @param list<MonthValue>    $statistics the totals minus the running month when it is excluded
     * @param list<ExcludedMonth> $excluded
     */
    private function __construct(
        public array $totals,
        public array $statistics,
        public array $excluded,
        private bool $hasMonthWithoutData,
    ) {
    }

    /** @param list<MonthValue> $months */
    public static function of(array $months, IncompleteMonths $incomplete): self
    {
        $totals = [];
        $statistics = [];
        $excluded = [];
        $noData = false;
        $seen = [];
        foreach ($months as $month) {
            if (isset($seen[$month->month->key()])) {
                throw new \InvalidArgumentException('A month appears only once in an aggregate.');
            }
            $seen[$month->month->key()] = true;
            $reason = match ($month->state) {
                MonthState::FUTURE => ExcludedReason::FUTURE,
                MonthState::NO_DATA => ExcludedReason::NO_DATA,
                default => null,
            };
            if (null !== $reason) {
                $excluded[] = new ExcludedMonth($month->month, $reason);
                $noData = $noData || MonthState::NO_DATA === $month->state;
                continue;
            }

            $totals[] = $month;
            if (MonthState::PROVISIONAL === $month->state && IncompleteMonths::EXCLUDE === $incomplete) {
                $excluded[] = new ExcludedMonth($month->month, ExcludedReason::PROVISIONAL);
                continue;
            }
            $statistics[] = $month;
        }

        return new self($totals, $statistics, $excluded, $noData);
    }

    /** The reason no figure can be produced at all, or null. A null policy or asset never counts as a second one. */
    public function guard(MonthValue ...$months): ?AggregateReason
    {
        return match (true) {
            [] === $this->totals => AggregateReason::EMPTY_POPULATION,
            count(array_unique(array_filter(array_map(static fn (MonthValue $m): ?int => $m->policyVersion, $months), static fn (?int $v): bool => null !== $v))) > 1 => AggregateReason::MIXED_METRIC_POLICIES,
            count($this->assets(...$months)) > 1 => AggregateReason::MIXED_ASSETS,
            default => null,
        };
    }

    public function asset(): ?AssetCode
    {
        $assets = $this->assets(...$this->totals);

        return 1 === count($assets) ? AssetCode::fromString(array_values($assets)[0]) : null;
    }

    public function policyVersion(): ?int
    {
        $versions = array_unique(array_filter(array_map(static fn (MonthValue $m): ?int => $m->policyVersion, $this->totals), static fn (?int $v): bool => null !== $v));

        return 1 === count($versions) ? array_values($versions)[0] : null;
    }

    /** The latest month of the totals, whatever the order the caller gave. */
    public function latest(): ?MonthValue
    {
        $latest = null;
        foreach ($this->totals as $month) {
            if (null === $latest || $month->month->key() > $latest->month->key()) {
                $latest = $month;
            }
        }

        return $latest;
    }

    public function quality(MonthValue ...$values): AggregateQuality
    {
        $missing = false;
        $provisional = false;
        foreach ($this->totals as $month) {
            $provisional = $provisional || MonthState::PROVISIONAL === $month->state;
        }
        foreach ($values as $value) {
            $missing = $missing || null === $value->value;
        }

        return match (true) {
            [] === $this->totals => AggregateQuality::EMPTY,
            $this->hasMonthWithoutData || $missing => AggregateQuality::PARTIAL,
            $provisional => AggregateQuality::PROVISIONAL,
            default => AggregateQuality::COMPLETE,
        };
    }

    /** @return array<string, string> */
    private function assets(MonthValue ...$months): array
    {
        $assets = [];
        foreach ($months as $month) {
            if (null !== $month->asset) {
                $assets[$month->asset->toString()] = $month->asset->toString();
            }
        }

        return $assets;
    }
}
