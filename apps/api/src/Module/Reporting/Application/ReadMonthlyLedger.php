<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\ReadMonthlyAccountFacts;
use App\Module\Accounts\Application\ReadPeriodStatus;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\InvalidCalendarMonth;
use App\Module\Categories\Application\BudgetCategoryFact;
use App\Module\Categories\Application\BudgetCategoryScopeTooLarge;
use App\Module\Categories\Application\ReadBudgetCategoryFacts;
use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reporting\Domain\MonthlyLedgerCalculator;
use App\Module\Reporting\Domain\MonthlyLedgerEntry;
use App\Module\Reporting\Domain\MonthlyLedgerTotal;
use App\Module\Transactions\Application\MonthlyTransactionScopeTooLarge;
use App\Module\Transactions\Application\ReadMonthlyTransactionFacts;
use App\Module\Transactions\Application\ReadMonthlyTransferPairs;

final readonly class ReadMonthlyLedger
{
    public function __construct(
        private CallerWorkspace $caller,
        private ReadMonthlyTransactionFacts $transactions,
        private ReadFirstDataMonth $firstDataMonth,
        private ReadMonthlyTransferPairs $transfers,
        private ReadMonthlyAccountFacts $accounts,
        private ReadBudgetCategoryFacts $categories,
        private ReadPeriodStatus $periodStatus,
        private WorkspaceTimezoneReader $timezones,
    ) {
    }

    public function __invoke(string $requestedMonth, ?string $requestedAxis): MonthlyLedgerView
    {
        [$month, $axis] = self::query($requestedMonth, $requestedAxis);
        $workspace = $this->caller->resolve();
        try {
            $transactions = ($this->transactions)($workspace, $month);
            $transfers = ($this->transfers)($workspace, $month);
            $accounts = ($this->accounts)($workspace, $month);
            $categories = ($this->categories)($workspace);
        } catch (MonthlyTransactionScopeTooLarge|NetWorthScopeTooLarge|BudgetCategoryScopeTooLarge $exception) {
            throw new MonthlyProjectionScopeTooLarge('The monthly ledger scope exceeds its bounds.', previous: $exception);
        }

        $entries = MonthlyLedgerFacts::entries($transactions->booked);
        $pendingEntries = MonthlyLedgerFacts::entries($transactions->pending);
        $incomeRows = self::categoryRows($categories, $entries, $pendingEntries, 'INCOME', null);
        $expenseRows = self::categoryRows($categories, $entries, $pendingEntries, 'EXPENSE', $axis?->value);
        $transferSources = MonthlyLedgerFacts::transferSourcesByAccount($transfers);
        $pendingTransferSources = MonthlyLedgerFacts::transferSourcesByAccount($transfers, pending: true);
        $accountRows = [];
        foreach ($accounts->accounts as $account) {
            $total = MonthlyLedgerCalculator::total($transferSources[$account->id] ?? [], invert: false);
            $pendingSources = $pendingTransferSources[$account->id] ?? [];
            $pending = MonthlyLedgerCalculator::total($pendingSources, invert: false);
            $accountRows[] = new MonthlyLedgerAccountRowView(
                $account->id,
                $account->label,
                $account->assetCode,
                $account->kind,
                self::metric(self::realised($total, $pending)),
                $total->movementCount,
                $total->hasMovements,
                $pending->value?->toString(),
                $pending->asset?->toString(),
                count($pendingSources),
            );
        }

        $invalidTransfer = [] !== array_filter($transfers, static fn ($pair): bool => !MonthlyLedgerFacts::wellFormedTransfer($pair));
        $period = ($this->periodStatus)($month->key());

        return new MonthlyLedgerView(
            $month->key(),
            $month->firstDay()->format('Y-m-d'),
            $month->lastDay()->format('Y-m-d'),
            $axis?->value,
            match (true) {
                $transactions->pendingCount > 0 => 'PENDING',
                [] === $transactions->booked => 'EMPTY',
                default => 'COMPLETE',
            },
            $invalidTransfer ? 'MISSING' : 'CURRENT',
            $this->timezones->timezone($workspace),
            ($this->firstDataMonth)($workspace),
            $transactions->pendingCount,
            null !== $period->closure,
            null === $period->closure,
            null === $period->closure ? null : 'PERIOD_CLOSED',
            $incomeRows,
            $expenseRows,
            $accountRows,
        );
    }

    /** @return array{CalendarMonth, ?AnalyticAxis} */
    public static function query(string $requestedMonth, ?string $requestedAxis): array
    {
        try {
            $month = CalendarMonth::fromString($requestedMonth);
        } catch (InvalidCalendarMonth $exception) {
            throw new InvalidMonthlyLedgerQuery($exception->getMessage(), previous: $exception);
        }
        $axis = null;
        if (null !== $requestedAxis && '' !== $requestedAxis) {
            $axis = AnalyticAxis::tryFrom($requestedAxis);
            if (null === $axis) {
                throw new InvalidMonthlyLedgerQuery('The monthly ledger axis is invalid.');
            }
        }

        return [$month, $axis];
    }

    /**
     * @param list<BudgetCategoryFact> $categories
     * @param list<MonthlyLedgerEntry> $entries
     * @param list<MonthlyLedgerEntry> $pendingEntries
     *
     * @return list<MonthlyLedgerCategoryRowView>
     */
    private static function categoryRows(array $categories, array $entries, array $pendingEntries, string $type, ?string $axis): array
    {
        $rows = [];
        foreach ($categories as $category) {
            if ($category->type !== $type) {
                continue;
            }
            $sources = MonthlyLedgerCalculator::categorySources($entries, $type, $category->id, $axis);
            $pendingSources = MonthlyLedgerCalculator::categorySources($pendingEntries, $type, $category->id, $axis);
            if (null !== $category->archivedAt && [] === $sources && [] === $pendingSources) {
                continue;
            }
            $total = MonthlyLedgerCalculator::total($sources, 'EXPENSE' === $type);
            $pending = MonthlyLedgerCalculator::total($pendingSources, 'EXPENSE' === $type);
            $rows[] = new MonthlyLedgerCategoryRowView(
                $category->id,
                $category->label,
                $category->icon,
                $category->color,
                $category->budgetIncluded,
                null !== $category->archivedAt,
                self::metric(self::realised($total, $pending)),
                $total->movementCount,
                $total->hasMovements,
                $pending->value?->toString(),
                $pending->asset?->toString(),
                count($pendingSources),
            );
        }

        return $rows;
    }

    /**
     * Nothing booked but something scheduled is a computable zero, not an unknown: the figure is exact
     * and only the pending amount (reported next to it) moves the row.
     */
    private static function realised(MonthlyLedgerTotal $booked, MonthlyLedgerTotal $pending): MonthlyLedgerTotal
    {
        if (null !== $booked->value || null === $pending->value || null === $pending->asset || $booked->hasMovements) {
            return $booked;
        }

        return new MonthlyLedgerTotal(ExactDecimal::subtract($pending->value, $pending->value), $pending->asset, null, 0, false);
    }

    private static function metric(MonthlyLedgerTotal $total): MonthlyMetricView
    {
        return new MonthlyMetricView($total->value?->toString(), $total->asset?->toString(), $total->reason);
    }
}
