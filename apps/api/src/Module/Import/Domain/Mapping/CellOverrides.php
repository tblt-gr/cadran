<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Mapping;

/** The per-month cell overrides of the mapping, keyed by `YYYY-MM`. */
final readonly class CellOverrides
{
    /**
     * @param array<string, MonthCellOverrides> $months
     */
    public function __construct(private array $months = [])
    {
    }

    public function of(int $year, int $month): MonthCellOverrides
    {
        return $this->months[sprintf('%04d-%02d', $year, $month)] ?? new MonthCellOverrides();
    }

    /**
     * The months of a year that carry a cell override.
     *
     * @return list<int>
     */
    public function monthsOf(int $year): array
    {
        $months = [];
        foreach ($this->months as $key => $overrides) {
            if (str_starts_with($key, sprintf('%04d-', $year)) && ([] !== $overrides->cached || [] !== $overrides->skipped)) {
                $months[] = (int) substr($key, 5);
            }
        }
        sort($months);

        return $months;
    }
}
