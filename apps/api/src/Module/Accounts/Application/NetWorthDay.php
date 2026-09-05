<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Catalog\Domain\BusinessDay;
use Symfony\Component\Clock\ClockInterface;

/**
 * Reads the business days a net-worth query is answered for.
 *
 * A missing day means today, never an open-ended range: an aggregate is
 * always dated, and the date is what makes the figure reproducible.
 */
final readonly class NetWorthDay
{
    public static function parse(?string $value, ClockInterface $clock): \DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return BusinessDay::fromDateTime($clock->now())->date;
        }

        try {
            return BusinessDay::fromIsoDate($value)->date;
        } catch (\Throwable $exception) {
            throw new InvalidNetWorthQuery('A net-worth date must be an ISO 8601 calendar day.', previous: $exception);
        }
    }

    /**
     * The same day one month earlier, kept inside that month: comparing
     * 31 March against "31 February" would silently jump into March and make
     * the delta compare a day with itself.
     */
    public static function previousMonth(\DateTimeImmutable $day): \DateTimeImmutable
    {
        $month = $day->modify('first day of previous month');
        $target = min((int) $day->format('d'), (int) $month->format('t'));

        return $month->setDate((int) $month->format('Y'), (int) $month->format('m'), $target);
    }

    /**
     * The last day of each of the $months months ending on $day. The final
     * point is $day itself, so the curve ends on the headline figure instead
     * of on a month end the reader never asked about.
     *
     * @return list<\DateTimeImmutable> oldest first
     */
    public static function monthEndsUpTo(\DateTimeImmutable $day, int $months): array
    {
        $dates = [];
        for ($offset = $months - 1; $offset >= 1; --$offset) {
            $dates[] = $day->modify(sprintf('first day of -%d month', $offset))->modify('last day of this month');
        }

        $dates[] = $day;

        return $dates;
    }
}
