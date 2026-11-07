<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;

/** Bounded transfer-pair read port consumed by monthly reporting. */
final readonly class ReadMonthlyTransferPairs
{
    public function __construct(private MonthlyTransferPairReader $pairs)
    {
    }

    /** @return list<MonthlyTransferPairFact> */
    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month): array
    {
        $pairs = $this->pairs->read(
            $workspace,
            $month->firstDay(),
            $month->lastDay(),
            ReadMonthlyTransactionFacts::MAX_TRANSACTIONS + 1,
        );
        if (count($pairs) > ReadMonthlyTransactionFacts::MAX_TRANSACTIONS) {
            throw new MonthlyTransactionScopeTooLarge(sprintf('A monthly projection reads at most %d transfer pairs.', ReadMonthlyTransactionFacts::MAX_TRANSACTIONS));
        }

        return $pairs;
    }

    /**
     * The pairs of several months in one read, bucketed by the month of either of their legs. A month holding
     * more pairs than a monthly read accepts is null, as its own read would have refused it.
     *
     * @param list<CalendarMonth> $months
     *
     * @return array<string, list<MonthlyTransferPairFact>|null> keyed by `YYYY-MM`
     */
    public function byMonth(WorkspaceScope $workspace, array $months): array
    {
        if ([] === $months) {
            return [];
        }
        $keys = array_flip(array_map(static fn (CalendarMonth $month): string => $month->key(), $months));
        $first = $months[0];
        $last = $months[0];
        foreach ($months as $month) {
            $first = $month->key() < $first->key() ? $month : $first;
            $last = $month->key() > $last->key() ? $month : $last;
        }
        $limit = count($months) * ReadMonthlyTransactionFacts::MAX_TRANSACTIONS;
        $pairs = $this->pairs->read($workspace, $first->firstDay(), $last->lastDay(), $limit + 1);
        if (count($pairs) > $limit) {
            // A range this dense cannot be bucketed within the bound: read month by month, each with its own refusal.
            $result = [];
            foreach ($months as $month) {
                try {
                    $result[$month->key()] = ($this)($workspace, $month);
                } catch (MonthlyTransactionScopeTooLarge) {
                    $result[$month->key()] = null;
                }
            }

            return $result;
        }
        $buckets = array_fill_keys(array_keys($keys), []);
        foreach ($pairs as $pair) {
            foreach (array_unique([substr($pair->sourceBookedOn ?? '', 0, 7), substr($pair->targetBookedOn ?? '', 0, 7)]) as $key) {
                if (isset($keys[$key])) {
                    $buckets[$key][] = $pair;
                }
            }
        }
        foreach ($buckets as $key => $bucket) {
            if (count($bucket) > ReadMonthlyTransactionFacts::MAX_TRANSACTIONS) {
                $buckets[$key] = null;
            }
        }

        return $buckets;
    }
}
