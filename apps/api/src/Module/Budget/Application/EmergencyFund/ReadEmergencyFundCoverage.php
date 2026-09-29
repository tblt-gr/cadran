<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\EmergencyFund;

use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Application\Goals\GoalMonthSource;
use App\Module\Budget\Domain\EmergencyFund\EmergencyFundCalculator;
use App\Module\Budget\Domain\EmergencyFund\EmergencyFundPolicy;
use App\Module\Budget\Domain\EmergencyFund\EmergencyFundPolicyRepository;
use App\Module\Budget\Domain\EmergencyFund\EssentialMode;
use App\Module\Budget\Domain\EmergencyFund\LiquidAccount;
use App\Module\Categories\Application\BudgetCategoryScopeTooLarge;
use App\Module\Categories\Application\ReadBudgetCategoryFacts;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Reporting\Application\MonthlyProjectionScopeTooLarge;
use App\Module\Reporting\Domain\Aggregation\ExcludedMonth;
use App\Module\Reporting\Domain\Aggregation\MonthValue;
use App\Module\Transactions\Application\MonthlyTransactionScopeTooLarge;

/** Computes how many months of essential expenses the eligible liquid assets cover on one day. It never writes. */
final readonly class ReadEmergencyFundCoverage
{
    public function __construct(
        private CallerWorkspace $caller,
        private EmergencyFundPolicyRepository $policies,
        private GoalMonthSource $months,
        private LiquidAccountSource $accounts,
        private ReadBudgetCategoryFacts $categories,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(?string $asOf): EmergencyFundCoverageView
    {
        $workspace = $this->caller->resolve();
        $day = $this->asOf($asOf);
        $policy = $this->policies->find($workspace) ?? EmergencyFundPolicy::default($workspace);
        $period = $policy->referencePeriod(CalendarMonth::containing($day));
        try {
            $months = $this->months->between($workspace, $period->start, $period->end);
            $values = EssentialExpenseMonths::of(
                $policy,
                $months,
                EssentialMode::CATEGORIES === $policy->essentialMode ? ($this->categories)($workspace) : null,
            );
            $accounts = $this->accounts->eligibleOn($workspace, $day);
        } catch (MonthlyProjectionScopeTooLarge|MonthlyTransactionScopeTooLarge|BudgetCategoryScopeTooLarge|NetWorthScopeTooLarge $exception) {
            throw new EmergencyFundScopeTooLarge('The emergency-fund scope exceeds its bounds.', previous: $exception);
        }
        $coverage = EmergencyFundCalculator::compute($accounts, $values, $policy->targetMonths, $period->expectedMonths);

        return new EmergencyFundCoverageView(
            $day->format('Y-m-d'),
            $coverage->liquidAssets,
            $coverage->liquidAssetCode,
            $coverage->liquidReason,
            array_map(static fn (LiquidAccount $account): array => [
                'id' => $account->id,
                'label' => $account->label,
                'value' => $account->value?->toString(),
                'assetCode' => $account->asset?->toString(),
                'valuedOn' => $account->valuedOn?->format('Y-m-d'),
                'stale' => $account->isStale(),
            ], $coverage->accounts),
            $period->start->key(),
            $period->end->key(),
            $coverage->usedMonths,
            $coverage->expectedMonths,
            array_map(static fn (ExcludedMonth $excluded): array => ['month' => $excluded->month->key(), 'reason' => $excluded->reason->value], $coverage->excludedMonths),
            $coverage->average,
            $coverage->averageReason,
            $coverage->referenceAssetCode,
            array_map(static fn (MonthValue $month): array => ['month' => $month->month->key(), 'value' => $month->value?->toString()], $values),
            $policy->targetMonths->toString(),
            $coverage->requiredAmount,
            $coverage->gap,
            $coverage->coveredMonths,
            $coverage->coveredMonthsDisplay,
            $coverage->reason,
            $coverage->quality,
        );
    }

    /** Workspace-local today by default; an explicit date is a real calendar day that has already begun. */
    private function asOf(?string $raw): \DateTimeImmutable
    {
        $today = $this->calendar->today();
        if (null === $raw) {
            return $today;
        }
        if (1 !== preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $raw, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || (int) $parts[1] <= CalendarMonth::MIN_YEAR) {
            throw new EmergencyFundInputError('invalid_emergency_fund_as_of', 'An as-of date is a calendar day written YYYY-MM-DD.');
        }
        $day = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
        if ($day > $today) {
            throw new EmergencyFundInputError('future_emergency_fund_as_of', 'An as-of date cannot be later than today.');
        }

        return $day;
    }
}
