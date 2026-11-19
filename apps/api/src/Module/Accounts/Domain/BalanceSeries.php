<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * The day-end balances of an account: for each day that carries a movement, the
 * latest declared balance at or before that day plus every movement dated after
 * the declared day up to and including that day. The order of movements inside
 * one day never matters because only the day total is read.
 */
final class BalanceSeries
{
    private function __construct()
    {
    }

    /**
     * @param list<DayBalance> $anchors   declared balances, ascending by day
     * @param list<DayBalance> $movements net movement of each day
     * @param list<DayBalance> $deltas    signed amounts of the write being checked
     *
     * @return list<DayBalance> ascending; days before $from or without an anchor are absent
     */
    public static function compute(array $anchors, array $movements, array $deltas, \DateTimeImmutable $from): array
    {
        $net = [];
        foreach ([...$movements, ...$deltas] as $movement) {
            $key = $movement->day->format('Y-m-d');
            $net[$key] = isset($net[$key]) ? ExactDecimal::add($net[$key], $movement->amount) : $movement->amount;
        }
        ksort($net);

        $anchorKeys = array_map(static fn (DayBalance $anchor): string => $anchor->day->format('Y-m-d'), $anchors);
        $fromKey = $from->format('Y-m-d');
        $series = [];
        $passed = [];
        $current = -1;
        $running = DecimalValue::zero();
        foreach ($net as $key => $amount) {
            $key = (string) $key;
            $latest = $current;
            while (isset($anchors[$latest + 1]) && $anchorKeys[$latest + 1] <= $key) {
                ++$latest;
            }
            if ($latest !== $current) {
                $current = $latest;
                $running = DecimalValue::zero();
                foreach ($passed as $passedKey => $passedAmount) {
                    if ((string) $passedKey > $anchorKeys[$current]) {
                        $running = ExactDecimal::add($running, $passedAmount);
                    }
                }
            }
            $passed[$key] = $amount;
            if ($current < 0) {
                continue;
            }
            if ($key > $anchorKeys[$current]) {
                $running = ExactDecimal::add($running, $amount);
            }
            if ($key >= $fromKey) {
                $series[] = new DayBalance(
                    new \DateTimeImmutable($key, new \DateTimeZone('UTC')),
                    ExactDecimal::add($anchors[$current]->amount, $running),
                );
            }
        }

        return $series;
    }
}
