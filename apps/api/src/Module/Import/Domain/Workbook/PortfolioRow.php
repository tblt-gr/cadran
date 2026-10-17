<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Workbook;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Import\Domain\Issue;

/**
 * A month row of a portfolio sheet. `month` is null for row 3, the opening
 * state (previous December). `deposits` is column C, kept only for the opening
 * state: a month's deposits come from the savings transfer, not from here.
 * `securities` is the securities value (PEA column E, CTO column H); an empty
 * cell is null, which means no valuation that month. A cash value is column B.
 *
 * The opening row (no month) is read lazily: its issues and warnings are kept
 * here and only reach the plan when the planner builds the opening state from it.
 */
final readonly class PortfolioRow
{
    /**
     * @param list<ParsedMovement> $buys     purchases in column then term order
     * @param list<Issue>          $issues   reading issues deferred to the planner (opening row only)
     * @param list<Warning>        $warnings reading warnings deferred to the planner (opening row only)
     */
    public function __construct(
        public int $row,
        public ?int $month,
        public ?DecimalValue $cash,
        public ?DecimalValue $deposits,
        public ?DecimalValue $securities,
        public array $buys,
        public array $issues = [],
        public array $warnings = [],
    ) {
    }
}
