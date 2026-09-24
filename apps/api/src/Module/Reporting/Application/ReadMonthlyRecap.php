<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\NetWorthContributionView;
use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\ResolveNetWorthContributions;
use App\Module\Accounts\Domain\NetWorthContribution;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Transactions\Application\ReadTransactionSummaries;

/**
 * The account, wealth and totals recap of one month.
 *
 * It composes, it does not recompute: the account rows, group subtotals and
 * shares come from the same exclusive roll-up the net-worth aggregate
 * publishes, and the movement totals come from the monthly projection. Only
 * the N−1 value of each account is read here, because the aggregate publishes
 * the compared day as a single figure rather than account by account.
 */
final readonly class ReadMonthlyRecap
{
    public function __construct(
        private CallerWorkspace $caller,
        private ReadMonthlyProjection $projection,
        private ResolveNetWorthContributions $contributions,
        private ReadTransactionSummaries $transactionSummaries,
    ) {
    }

    public function __invoke(string $requestedMonth): MonthlyRecapView
    {
        $projection = ($this->projection)($requestedMonth);
        $workspace = $this->caller->resolve();

        $comparedOn = new \DateTimeImmutable($projection->netWorthComparedOn, new \DateTimeZone('UTC'));
        try {
            $previous = $this->contributions->on($workspace, [$comparedOn]);
        } catch (NetWorthScopeTooLarge $exception) {
            throw new MonthlyProjectionScopeTooLarge('The monthly recap scope exceeds its bounds.', previous: $exception);
        }
        $previousByAccount = [];
        foreach ($previous->on($comparedOn) as $contribution) {
            $previousByAccount[$contribution->accountId] = $contribution;
        }
        $sourceTransactionsById = [];
        foreach (($this->transactionSummaries)($workspace, self::sourceTransactionIds($projection)) as $transaction) {
            $sourceTransactionsById[$transaction->id] = $transaction;
        }

        $accounts = array_map(
            static fn (NetWorthContributionView $current): MonthlyRecapAccountView => MonthlyRecapAccountView::of(
                $current,
                self::previousOf($previousByAccount, $current->accountId),
            ),
            $projection->netWorth->contributions,
        );

        return new MonthlyRecapView(
            month: $projection->month,
            previousAsOf: $projection->netWorthComparedOn,
            currentAsOf: $projection->netWorthAsOf,
            provisional: $projection->provisional,
            state: $projection->state,
            quality: $projection->netWorth->quality,
            accounts: $accounts,
            groups: $projection->netWorth->allocation,
            netWorth: MonthlyRecapNetWorthView::of($projection->netWorth),
            totals: MonthlyRecapTotalsView::of($projection, $sourceTransactionsById),
        );
    }

    /** @return list<string> */
    private static function sourceTransactionIds(MonthlyProjectionView $projection): array
    {
        $ids = [];
        foreach ($projection->expensesByAxis as $metric) {
            foreach ($metric->sourceTransactionIds as $id) {
                $ids[$id] = true;
            }
        }
        foreach ($projection->categoryMetrics as $category) {
            foreach ($category->metric->sourceTransactionIds as $id) {
                $ids[$id] = true;
            }
        }

        $uniqueIds = array_keys($ids);
        sort($uniqueIds, SORT_STRING);

        return $uniqueIds;
    }

    /** @param array<string, NetWorthContribution> $previousByAccount */
    private static function previousOf(array $previousByAccount, string $accountId): ?NetWorthContribution
    {
        return $previousByAccount[$accountId] ?? null;
    }
}
