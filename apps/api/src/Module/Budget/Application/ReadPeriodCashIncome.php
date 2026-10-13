<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\BudgetIncomeCalculator;
use App\Module\Budget\Domain\BudgetPeriod;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\MetricPolicyReference;
use App\Module\Reporting\Application\ResolveMetricPolicy;
use App\Module\Reporting\Domain\MonthlyMetric;
use App\Module\Reporting\Domain\MonthlyMovement;
use App\Module\Reporting\Domain\MonthlyMovementKind;
use App\Module\Reporting\Domain\MonthlyProjectionReason;
use App\Module\Transactions\Application\BookedMovementFact;
use App\Module\Transactions\Application\BookedMovementReader;
use App\Module\Transactions\Application\ReadMonthlyTransactionFacts;

/**
 * The "period cash income" a ratio target resolves against, for either a
 * MONTH or a YEAR budget period. It extends RPT-001's own definition to an
 * arbitrary bounded date range rather than copying its `CalendarMonth`-only
 * reads: {@see AccountRepository::listOpenDuring()} and
 * {@see BookedMovementReader::between()} already accept a plain date range,
 * so a year is read exactly like a month, only over twelve times the span.
 */
final readonly class ReadPeriodCashIncome
{
    public const int MAX_ACCOUNTS = 500;
    /** A YEAR period is twelve months of the monthly projection's volume. */
    public const int MAX_TRANSACTIONS = 12 * ReadMonthlyTransactionFacts::MAX_TRANSACTIONS;

    public function __construct(
        private AccountRepository $accounts,
        private BookedMovementReader $movements,
        private WorkspaceTimezoneReader $timezones,
        private ResolveMetricPolicy $metricPolicy,
    ) {
    }

    public function policy(WorkspaceScope $workspace, BudgetPeriod $period): PeriodMetricPolicy
    {
        $months = [];
        for ($cursor = $period->firstDay(); $cursor <= $period->lastDay(); $cursor = $cursor->modify('first day of next month')) {
            $months[] = CalendarMonth::containing($cursor);
        }
        $resolved = $this->metricPolicy->forMonths($workspace, $months);
        foreach ($resolved as $candidate) {
            if ($candidate->version !== $resolved[0]->version) {
                return new PeriodMetricPolicy(new MetricPolicyReference(null, null), null, true);
            }
        }

        return new PeriodMetricPolicy($resolved[0]->reference(), $resolved[0]->policy, false);
    }

    public function __invoke(WorkspaceScope $workspace, BudgetPeriod $period, ?PeriodMetricPolicy $metricPolicy = null): MonthlyMetric
    {
        $metricPolicy ??= $this->policy($workspace, $period);
        if ($metricPolicy->mixed) {
            return MonthlyMetric::missing(MonthlyProjectionReason::MIXED_METRIC_POLICIES);
        }
        $first = $period->firstDay();
        $last = $period->lastDay();
        $timezone = new \DateTimeZone($this->timezones->timezone($workspace));

        $accounts = $this->accounts->listOpenDuring($workspace, $first, $last, $timezone, self::MAX_ACCOUNTS + 1);
        if (count($accounts) > self::MAX_ACCOUNTS) {
            throw new BudgetIncomeScopeTooLarge('A budget period income reads at most 500 accounts.');
        }

        // Scalar facts, not hydrated transactions: a year at the bound is 24 000 rows and must not exhaust memory.
        $rows = $this->movements->between($workspace, $first, $last, self::MAX_TRANSACTIONS + 1);
        if (count($rows) > self::MAX_TRANSACTIONS) {
            throw new BudgetIncomeScopeTooLarge(sprintf('A budget period income reads at most %d transactions.', self::MAX_TRANSACTIONS));
        }

        $kindByAccount = [];
        foreach ($accounts as $account) {
            $kindByAccount[$account->id] = $account->kind->value;
        }
        $movements = array_map(
            static fn (BookedMovementFact $fact): MonthlyMovement => new MonthlyMovement(
                $fact->amount->value,
                $fact->amount->asset,
                MonthlyMovementKind::from($fact->nature),
                [],
                false,
                $fact->id,
                $kindByAccount[$fact->accountId] ?? null,
            ),
            $rows,
        );

        return BudgetIncomeCalculator::sumIncome(
            array_map(static fn (Account $account): string => $account->assetCode->toString(), $accounts),
            $movements,
            $metricPolicy->policy,
        );
    }
}
