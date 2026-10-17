<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Plan;

use App\Module\Import\Domain\Issue;
use App\Module\Import\Domain\Workbook\Warning;

/**
 * What one workbook becomes in the workspace: the ordered steps, the
 * instruments to create, and every issue found while reading and planning.
 * A plan with a rejection is never executed: the year is imported entirely
 * or not at all.
 */
final readonly class ImportPlan
{
    /**
     * @param list<PlannedStep>       $steps
     * @param list<PlannedInstrument> $instrumentsToCreate
     * @param array<string, string>   $existingInstruments instrument name => identifier in the workspace
     * @param list<Issue>             $issues              reading and planning issues, in order
     * @param list<Warning>           $warnings
     * @param list<string>            $ignoredSheets
     */
    public function __construct(
        public int $year,
        public array $steps,
        public array $instrumentsToCreate,
        public array $existingInstruments,
        public array $issues,
        public array $warnings,
        public array $ignoredSheets,
        public int $ignoredLines,
    ) {
    }

    /**
     * @return list<Issue>
     */
    public function rejections(): array
    {
        return array_values(array_filter($this->issues, static fn (Issue $issue): bool => $issue->isRejection()));
    }

    /**
     * @return list<Issue>
     */
    public function exclusions(): array
    {
        return array_values(array_filter($this->issues, static fn (Issue $issue): bool => !$issue->isRejection()));
    }

    public function hasRejections(): bool
    {
        return [] !== $this->rejections();
    }

    /**
     * Records per month and kind, in execution order.
     *
     * @return array<int, array<string, int>>
     */
    public function countsByMonth(): array
    {
        $counts = [];
        foreach ($this->steps as $step) {
            $counts[$step->month()][$step->kind()->name] = ($counts[$step->month()][$step->kind()->name] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }
}
