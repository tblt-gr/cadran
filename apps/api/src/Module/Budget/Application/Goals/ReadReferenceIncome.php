<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\Aggregation\MonthState;

final readonly class ReadReferenceIncome
{
    public function __construct(private GoalMonthSource $months)
    {
    }

    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month): ReferenceIncomeView
    {
        $endDate = $month->firstDay()->modify('-1 month');
        $startDate = $endDate->modify('-11 months');
        $start = CalendarMonth::containing($startDate);
        $end = CalendarMonth::containing($endDate);
        $values = [];
        $excluded = [];
        foreach ($this->months->between($workspace, $start, $end) as $item) {
            $income = $item->figures?->cells['cashIncome'] ?? null;
            if (MonthState::COMPLETE === $item->state && null !== $income?->value) {
                $values[] = DecimalValue::fromString($income->value);
                continue;
            }
            $excluded[] = ['month' => $item->month->key(), 'reason' => MonthState::COMPLETE === $item->state ? ($income->reason ?? 'MISSING_CASH_INCOME') : $item->state->value];
        }
        if ([] === $values) {
            return new ReferenceIncomeView($month->key(), null, 0, $start->key(), $end->key(), 'NO_REFERENCE_INCOME', $excluded);
        }

        return new ReferenceIncomeView(
            $month->key(),
            ExactDecimal::divide(ExactDecimal::sum(...$values), DecimalValue::fromString((string) count($values))),
            count($values),
            $start->key(),
            $end->key(),
            null,
            $excluded,
        );
    }
}
