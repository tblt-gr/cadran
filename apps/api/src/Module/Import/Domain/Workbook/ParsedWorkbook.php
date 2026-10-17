<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Workbook;

use App\Module\Import\Domain\Issue;

/**
 * Everything read from one yearly workbook, before any mapping: the entry
 * point of the planner. Every amount is an exact decimal, every problem is an
 * {@see Issue} located by sheet and cell, and nothing was written anywhere.
 */
final readonly class ParsedWorkbook
{
    /**
     * @param list<ParsedMonth> $months        ascending by month
     * @param list<string>      $ignoredSheets names of the sheets that are not read, for the report
     * @param list<Issue>       $issues        rejections and exclusions, in reading order
     * @param list<Warning>     $warnings
     */
    public function __construct(
        public int $year,
        public array $months,
        public ?ParsedPortfolioSheet $pea,
        public ?ParsedPortfolioSheet $ctoTr,
        public array $ignoredSheets,
        public array $issues,
        public array $warnings,
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

    public function month(int $month): ?ParsedMonth
    {
        foreach ($this->months as $parsed) {
            if ($parsed->month === $month) {
                return $parsed;
            }
        }

        return null;
    }
}
