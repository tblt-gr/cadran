<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

use App\Module\Accounts\Domain\CalendarMonth;

/**
 * The months for which a portfolio account may owe a month-end valuation: its
 * last day has come in the workspace calendar, it is at most twelve months
 * before the current one and it does not precede the account's opening or
 * follow its closing. Closures and valuations are applied afterwards.
 */
final class DueMonthWindow
{
    public const int MAX_MONTHS_BACK = 12;

    /** @return list<CalendarMonth> oldest first */
    public static function candidates(\DateTimeImmutable $today, \DateTimeImmutable $openedOn, ?\DateTimeImmutable $closedOn): array
    {
        $current = CalendarMonth::containing($today);
        $earliest = $current->firstDay()->modify('-'.self::MAX_MONTHS_BACK.' months');
        $start = $openedOn > $earliest ? $openedOn : $earliest;
        $month = CalendarMonth::containing($start);

        $months = [];
        while ($month->firstDay() <= $current->firstDay()) {
            if ($month->lastDay() <= $today && (null === $closedOn || $closedOn >= $month->firstDay())) {
                $months[] = $month;
            }
            $month = CalendarMonth::containing($month->firstDay()->modify('+1 month'));
        }

        return $months;
    }
}
