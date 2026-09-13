<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * Decides which expected instalment a real movement settles.
 *
 * The rule is deliberately narrow and deterministic: a movement settles the
 * nearest unmatched occurrence it fits, and an exact tie takes the earlier
 * expected date. A stale or ambiguous match would attribute a real movement to
 * the wrong instalment, so anything the rule cannot single out settles nothing.
 */
final class OccurrenceMatcher
{
    /** A movement may settle an instalment expected at most this many days away. */
    public const int MATCH_WINDOW_DAYS = 7;

    /**
     * @param list<TransactionRecurrenceOccurrence> $occurrences the instalments of the movement's own account
     */
    public static function select(array $occurrences, AssetAmount $amount, \DateTimeImmutable $bookedOn): ?TransactionRecurrenceOccurrence
    {
        $best = null;
        $bestKey = null;
        foreach ($occurrences as $occurrence) {
            if (!self::accepts($occurrence, $amount, $bookedOn)) {
                continue;
            }
            $key = [self::distanceInDays($occurrence->expectedOn, $bookedOn), $occurrence->expectedOn->format('Y-m-d'), $occurrence->id];
            if (null === $bestKey || $key < $bestKey) {
                $best = $occurrence;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * Whether this movement could settle this instalment at all: the instalment
     * is still expected, the denominations agree, the amount sits inside the
     * stored tolerance and the booked day inside the matching window.
     */
    public static function accepts(TransactionRecurrenceOccurrence $occurrence, AssetAmount $amount, \DateTimeImmutable $bookedOn): bool
    {
        return OccurrenceStatus::EXPECTED === $occurrence->status && self::fits($occurrence, $amount, $bookedOn);
    }

    /**
     * The same denomination, tolerance and window test, without asking whether
     * the instalment is still free. An edit re-reads it against the movement
     * that already settled it, to learn whether that settlement still holds.
     */
    public static function fits(TransactionRecurrenceOccurrence $occurrence, AssetAmount $amount, \DateTimeImmutable $bookedOn): bool
    {
        if (!$amount->asset->equals($occurrence->expectedAmount->asset)
            || self::distanceInDays($occurrence->expectedOn, $bookedOn) > self::MATCH_WINDOW_DAYS) {
            return false;
        }

        $floor = ExactDecimal::subtract($occurrence->expectedAmount->value, $occurrence->amountTolerance->value);
        $ceiling = ExactDecimal::add($occurrence->expectedAmount->value, $occurrence->amountTolerance->value);

        return $amount->value->compareTo($floor) >= 0 && $amount->value->compareTo($ceiling) <= 0;
    }

    private static function distanceInDays(\DateTimeImmutable $expectedOn, \DateTimeImmutable $bookedOn): int
    {
        return (int) self::midnight($expectedOn)->diff(self::midnight($bookedOn))->days;
    }

    private static function midnight(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
