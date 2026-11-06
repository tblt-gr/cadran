<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Transactions\Domain\BulkMoveTarget;
use App\Module\Transactions\Domain\Transaction;

/**
 * The balance effect of moving the eligible rows, in memory and in exact decimals.
 *
 * Account change: every row takes its amount off its account and adds it to the target, so the deltas sum to
 * zero. The source account is affected from the earliest old day, the target from the earliest new day.
 * Date change alone: money stays on its account, the delta is zero and the balance differs only between the
 * earliest and the latest of the old and new days. A row whose date does not change moves nothing.
 */
final readonly class ComputeBulkMoveImpact
{
    /**
     * @param list<Transaction>             $rows     the rows that will move
     * @param array<string, BulkMoveTarget> $accounts every account the rows sit on, by identifier
     *
     * @return list<BulkMoveImpact> ordered by label then identifier
     */
    public static function of(array $rows, array $accounts, ?BulkMoveTarget $target, ?\DateTimeImmutable $newDate): array
    {
        /** @var array<string, array{delta: DecimalValue, from: string, to: ?string}> $totals */
        $totals = [];
        foreach ($rows as $row) {
            $oldDay = $row->bookedOn->format('Y-m-d');
            $newDay = ($newDate ?? $row->bookedOn)->format('Y-m-d');
            if (null !== $target) {
                self::accumulate($totals, $row->accountId, ExactDecimal::negate($row->amount->value), $oldDay, null);
                self::accumulate($totals, $target->accountId, $row->amount->value, $newDay, null);
            } elseif ($oldDay !== $newDay) {
                self::accumulate($totals, $row->accountId, DecimalValue::zero(), min($oldDay, $newDay), max($oldDay, $newDay));
            }
        }

        $impacts = [];
        foreach ($totals as $accountId => $total) {
            $account = $target?->accountId === $accountId ? $target : $accounts[$accountId];
            $impacts[] = new BulkMoveImpact($accountId, $account->label, $account->assetCode, $total['delta']->toString(), $total['from'], $total['to']);
        }
        usort($impacts, static fn (BulkMoveImpact $left, BulkMoveImpact $right): int => [$left->label, $left->accountId] <=> [$right->label, $right->accountId]);

        return $impacts;
    }

    /** @param array<string, array{delta: DecimalValue, from: string, to: ?string}> $totals */
    private static function accumulate(array &$totals, string $accountId, DecimalValue $delta, string $from, ?string $to): void
    {
        $known = $totals[$accountId] ?? null;
        $totals[$accountId] = [
            'delta' => null === $known ? $delta : ExactDecimal::add($known['delta'], $delta),
            'from' => null === $known ? $from : min($known['from'], $from),
            'to' => null === $known || null === $known['to'] ? $to : (null === $to ? $known['to'] : max($known['to'], $to)),
        ];
    }
}
