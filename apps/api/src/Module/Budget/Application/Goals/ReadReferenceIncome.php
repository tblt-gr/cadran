<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Domain\Aggregation\MonthState;

final readonly class ReadReferenceIncome
{
    public function __construct(private GoalMonthSource $months)
    {
    }

    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month): ReferenceIncomeView
    {
        return self::fromMonths($month, $this->months->between($workspace, self::windowStart($month), self::windowEnd($month)));
    }

    /** The first month of the twelve complete months preceding the reference month. */
    public static function windowStart(CalendarMonth $month): CalendarMonth
    {
        return CalendarMonth::containing($month->firstDay()->modify('-12 months'));
    }

    /**
     * Averages the reference window out of months already loaded; months outside the window are ignored.
     *
     * @param list<AnnualMonth> $loaded
     */
    public static function fromMonths(CalendarMonth $month, array $loaded): ReferenceIncomeView
    {
        $start = self::windowStart($month);
        $end = self::windowEnd($month);
        $values = [];
        $excluded = [];
        $policyVersions = [];
        foreach ($loaded as $item) {
            if ($item->month->key() < $start->key() || $item->month->key() > $end->key()) {
                continue;
            }
            $income = $item->figures?->cells['cashIncome'] ?? null;
            if (MonthState::COMPLETE === $item->state && null !== $income?->value) {
                $values[] = DecimalValue::fromString($income->value);
                $policyVersions[] = $item->figures->policyVersion;
                continue;
            }
            $excluded[] = ['month' => $item->month->key(), 'reason' => MonthState::COMPLETE === $item->state ? ($income->reason ?? 'MISSING_CASH_INCOME') : $item->state->value];
        }
        $policyVersions = array_values(array_unique($policyVersions));
        sort($policyVersions);
        if ([] === $values) {
            return new ReferenceIncomeView($month->key(), null, 0, $start->key(), $end->key(), 'NO_REFERENCE_INCOME', $excluded, $policyVersions);
        }

        return new ReferenceIncomeView(
            $month->key(),
            ExactDecimal::divide(ExactDecimal::sum(...$values), DecimalValue::fromString((string) count($values))),
            count($values),
            $start->key(),
            $end->key(),
            null,
            $excluded,
            $policyVersions,
        );
    }

    private static function windowEnd(CalendarMonth $month): CalendarMonth
    {
        return CalendarMonth::containing($month->firstDay()->modify('-1 month'));
    }
}
