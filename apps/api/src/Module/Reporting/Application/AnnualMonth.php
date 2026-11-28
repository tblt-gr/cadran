<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reporting\Application\Facade\ReportFigure;
use App\Module\Reporting\Application\Facade\ReportFigureKey;
use App\Module\Reporting\Domain\Aggregation\MonthState;

/** One month of an annual report: its state and, when it has data, the figures of a live read or of its snapshot. */
final readonly class AnnualMonth
{
    public function __construct(
        public CalendarMonth $month,
        public MonthState $state,
        public bool $closed,
        public bool $snapshot,
        public ?MonthFigures $figures,
    ) {
    }

    public function figure(ReportFigureKey $key): ReportFigure
    {
        $metric = $this->figures?->cells[$key->id] ?? null;
        if (null === $metric) {
            return new ReportFigure(null, null, 'MISSING_MONTH_VALUE', false);
        }

        return new ReportFigure(
            null === $metric->value ? null : DecimalValue::fromString($metric->value),
            null === $metric->assetCode ? null : AssetCode::fromString($metric->assetCode),
            $metric->reason,
            null === $metric->value && AnnualColumnSeries::NO_MOVEMENTS === $metric->reason,
        );
    }

    /** Null when the month has no figures at all. */
    public function hasTransactions(): ?bool
    {
        return $this->figures?->hasTransactions;
    }

    /** Null when the month has no figures at all. */
    public function policyVersion(): ?int
    {
        return $this->figures?->policyVersion;
    }

    /** @return list<string> the expense categories counted in the budget when the month was read */
    public function budgetExpenseCategoryIds(): array
    {
        return $this->figures->budgetExpenseCategoryIds ?? [];
    }
}
