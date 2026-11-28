<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application\Facade;

use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Domain\Aggregation\ColumnKind;

/**
 * Names one report figure another module may read. With {@see MonthFigures}, which writes its cells
 * through these constructors, it is the only holder of the cell grammar.
 */
final readonly class ReportFigureKey
{
    private function __construct(
        public string $id,
        public ColumnKind $kind,
    ) {
    }

    public static function cashIncome(): self
    {
        return new self('cashIncome', ColumnKind::FLOW);
    }

    public static function budgetExpenses(): self
    {
        return new self('budgetExpenses', ColumnKind::FLOW);
    }

    public static function budgetSurplus(): self
    {
        return new self('budgetSurplus', ColumnKind::FLOW);
    }

    public static function netSavingsTransfers(): self
    {
        return new self('netSavingsTransfers', ColumnKind::FLOW);
    }

    public static function cashSavingsRate(): self
    {
        return new self('cashSavingsRate', ColumnKind::RATE);
    }

    public static function netSavingsRate(): self
    {
        return new self('netSavingsRate', ColumnKind::RATE);
    }

    public static function axisSpending(string $axis): self
    {
        return new self('axis:'.$axis, ColumnKind::FLOW);
    }

    /** A category's own movements, without its descendants, so a branch is summed without double counting. */
    public static function ownCategorySpending(string $categoryId): self
    {
        return new self(MonthFigures::OWN_CATEGORY_PREFIX.$categoryId, ColumnKind::FLOW);
    }
}
