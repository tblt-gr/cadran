<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Reconciliation;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionState;

/**
 * Decides which pending row an incoming booked movement settles when no stable
 * external identifier answers the question.
 *
 * The rule is deliberately narrower than the recurrence matcher it is shaped
 * after: the amount must be identical to its last stored digit, and an exact
 * tie is not resolved by any heuristic at all. Two rows a few days apart for
 * the same amount are indistinguishable to this code, and guessing would merge
 * two real movements into one, so a tie settles nothing and goes to a human.
 */
final class ReconciliationMatcher
{
    /**
     * A movement may settle a pending row booked at most this many days away.
     * Deliberately its own constant: this window answers "how late does a
     * provider book what it authorised", not the recurrence question
     * {@see \App\Module\Transactions\Domain\Recurrence\OccurrenceMatcher::MATCH_WINDOW_DAYS} answers.
     */
    public const int MATCH_WINDOW_DAYS = 5;

    /**
     * The single pending row this movement settles, or null when the rule
     * cannot single one out — no eligible row, or several equally close ones.
     *
     * @param list<Transaction> $pending pending rows of the movement's own account
     */
    public static function select(array $pending, AssetAmount $amount, \DateTimeImmutable $bookedOn): ?Transaction
    {
        $best = null;
        $bestDistance = null;
        $tied = false;
        foreach (self::candidates($pending, $amount, $bookedOn) as $candidate) {
            $distance = self::distanceInDays($candidate->bookedOn, $bookedOn);
            if (null === $bestDistance || $distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
                $tied = false;
                continue;
            }
            if ($distance === $bestDistance) {
                $tied = true;
            }
        }

        return $tied ? null : $best;
    }

    /**
     * Every pending row the movement could settle, in the order given. A human
     * chooses between them when {@see select} refuses to.
     *
     * @param list<Transaction> $pending
     *
     * @return list<Transaction>
     */
    public static function candidates(array $pending, AssetAmount $amount, \DateTimeImmutable $bookedOn): array
    {
        return array_values(array_filter(
            $pending,
            static fn (Transaction $candidate): bool => self::accepts($candidate, $amount, $bookedOn),
        ));
    }

    /**
     * Whether this movement could settle this row at all: the row is still
     * pending, the denominations agree, the signed amounts are equal to the
     * last stored digit and the booked day sits inside the window.
     */
    public static function accepts(Transaction $candidate, AssetAmount $amount, \DateTimeImmutable $bookedOn): bool
    {
        return TransactionState::PENDING === $candidate->state
            && $amount->asset->equals($candidate->amount->asset)
            && 0 === $amount->value->compareTo($candidate->amount->value)
            && self::distanceInDays($candidate->bookedOn, $bookedOn) <= self::MATCH_WINDOW_DAYS;
    }

    private static function distanceInDays(\DateTimeImmutable $candidateBookedOn, \DateTimeImmutable $bookedOn): int
    {
        return (int) self::midnight($candidateBookedOn)->diff(self::midnight($bookedOn))->days;
    }

    private static function midnight(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
