<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Accounts\Domain\Account;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\Performance\PerformancePeriod;
use App\Module\Portfolio\Domain\Performance\PerformanceScope;
use App\Module\Portfolio\Domain\Performance\ReturnTrend;
use App\Module\Portfolio\Domain\Performance\ReturnTrendReason;

/**
 * The chained monthly return of the twelve complete months before the current one.
 *
 * Each month goes through the `MONTH` scope of the single-period figures, on one shared read of the
 * account history, exactly like the months of the annual table. Months before the chain starts are
 * never computed.
 */
final readonly class PortfolioReturnTrend
{
    public function __construct(
        private PerformancePeriodBuilder $builder,
        private ComputePortfolioPerformance $compute,
    ) {
    }

    public function __invoke(WorkspaceScope $workspace, Account $account, \DateTimeImmutable $today): ReturnTrend
    {
        $window = ReturnTrend::windowBefore($today);
        try {
            $history = $this->builder->history($workspace, $account);
        } catch (PerformanceRangeTooDense) {
            // One heavy account must not take the whole list down: only its own trend is unavailable.
            return ReturnTrend::unavailable($window, ReturnTrendReason::TOO_MANY_OPERATIONS);
        }
        if ([] === $history) {
            return ReturnTrend::compute($window, array_fill(0, ReturnTrend::MONTHS, null), ReturnTrend::MONTHS);
        }

        // The later of the opening and the first operation, the rule of the annual table.
        $start = max($account->openedOn, $history[0]->details->tradeDate);
        $first = new \DateTimeImmutable($window[0].'-01', new \DateTimeZone('UTC'));
        $firstIndex = ((int) $start->format('Y') - (int) $first->format('Y')) * 12 + (int) $start->format('n') - (int) $first->format('n');

        $rates = [];
        foreach ($window as $position => $month) {
            if ($position < $firstIndex) {
                $rates[] = null;

                continue;
            }
            $rate = ($this->compute)($workspace, $account, PerformancePeriod::of(PerformanceScope::MONTH->value, $month, $today, $account->openedOn), $today, $history)
                ->modifiedDietz->unroundedRate;
            $rates[] = $rate?->toString();
        }

        return ReturnTrend::compute($window, $rates, $firstIndex);
    }
}
