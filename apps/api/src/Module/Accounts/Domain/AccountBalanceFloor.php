<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\DecimalValue;

/**
 * The lowest balance an interactive write may leave on a guarded account.
 *
 * A write is refused when it creates or deepens a shortfall: the lowest day-end
 * balance after it is under the limit and lower than the lowest one before it.
 * An account that is already negative keeps accepting writes that do not push
 * it lower, such as a deposit.
 */
final class AccountBalanceFloor
{
    /** The authorized overdraft will replace this by its negation. */
    public const string LIMIT = '0';

    private function __construct()
    {
    }

    /**
     * @param list<DayBalance> $before day-end balances of the window before the write
     * @param list<DayBalance> $after  the same window after the write
     *
     * @return \DateTimeImmutable|null the first day the write leaves under the limit, or null when accepted
     */
    public static function breach(array $before, array $after): ?\DateTimeImmutable
    {
        $limit = DecimalValue::fromString(self::LIMIT);
        $lowestAfter = self::lowest($after);
        if (null === $lowestAfter || $lowestAfter->compareTo($limit) >= 0) {
            return null;
        }
        $lowestBefore = self::lowest($before);
        if (null !== $lowestBefore && $lowestAfter->compareTo($lowestBefore) >= 0) {
            return null;
        }

        $beforeByDay = [];
        foreach ($before as $entry) {
            $beforeByDay[$entry->day->format('Y-m-d')] = $entry->amount;
        }
        $first = null;
        foreach ($after as $entry) {
            if ($entry->amount->compareTo($limit) >= 0) {
                continue;
            }
            $first ??= $entry->day;
            $previous = $beforeByDay[$entry->day->format('Y-m-d')] ?? null;
            // A day that was already this negative is not what the write caused.
            if (null === $previous || 0 !== $previous->compareTo($entry->amount)) {
                return $entry->day;
            }
        }

        return $first;
    }

    /** @param list<DayBalance> $series */
    private static function lowest(array $series): ?DecimalValue
    {
        $lowest = null;
        foreach ($series as $entry) {
            if (null === $lowest || $entry->amount->compareTo($lowest) < 0) {
                $lowest = $entry->amount;
            }
        }

        return $lowest;
    }
}
