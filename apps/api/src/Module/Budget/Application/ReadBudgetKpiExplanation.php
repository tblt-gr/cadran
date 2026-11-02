<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Reporting\Application\MonthlyBudgetSourceView;
use App\Module\Reporting\Application\MonthlyKpiExplanationView;
use App\Module\Reporting\Application\MonthlyKpiReasonExplanation;
use App\Module\Transactions\Application\ReadTransactionSummaries;

final readonly class ReadBudgetKpiExplanation
{
    public function __construct(
        private ReadBudgetComparisons $comparisons,
        private CallerWorkspace $caller,
        private ReadTransactionSummaries $transactionSummaries,
    ) {
    }

    public function __invoke(string $planId, string $targetId, bool $includeUpcoming = false): MonthlyKpiExplanationView
    {
        $comparisons = ($this->comparisons)($planId, $includeUpcoming);
        $comparison = null;
        foreach ($comparisons->comparisons as $candidate) {
            if ($candidate->targetId === $targetId) {
                $comparison = $candidate;
                break;
            }
        }
        if (null === $comparison) {
            throw new BudgetKpiExplanationNotFound('This budget comparison does not exist in this workspace.');
        }

        $month = CalendarMonth::fromString($comparisons->period);
        $reason = $comparison->actualReason ?? $comparison->targetReason;
        $sourceTransactionIds = array_map(
            static fn (MonthlyBudgetSourceView $source): string => $source->id,
            $comparison->includedTransactions,
        );
        sort($sourceTransactionIds, SORT_STRING);

        return new MonthlyKpiExplanationView(
            kpi: 'budgetComparison',
            value: $comparison->variance,
            assetCode: $comparisons->assetCode,
            reason: $reason,
            reasonExplanation: MonthlyKpiReasonExplanation::for($reason),
            formula: 'budget variance = resolved target - scoped actual'.($includeUpcoming ? '; the scoped actual includes the planned upcoming transactions of the month as if booked on their date' : ''),
            scope: sprintf('%s budget scope “%s” in the caller workspace.', $comparison->scopeType, $comparison->scopeLabel)
                .($includeUpcoming ? ' Planned upcoming transactions (pending, booked automatically on their date) are included.' : ''),
            periodStart: $month->firstDay()->format('Y-m-d'),
            periodEnd: $month->lastDay()->format('Y-m-d'),
            sourceTransactionIds: $sourceTransactionIds,
            sourceTransactions: ($this->transactionSummaries)($this->caller->resolve(), $sourceTransactionIds),
            sourceTransferIds: [],
            sourceAccountIds: [],
            freshness: $comparison->pendingCount > 0 ? 'PENDING' : 'CURRENT',
            quality: $comparison->status,
            pendingCount: $comparison->pendingCount,
            metricPolicy: $comparisons->metricPolicy,
            includesUpcoming: $includeUpcoming,
            upcomingCount: $comparison->upcomingCount,
        );
    }
}
