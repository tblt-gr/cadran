<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

enum MonthlyKpi: string
{
    case CASH_INCOME = 'cashIncome';
    case NON_CASH_BENEFITS = 'nonCashBenefits';
    case BUDGET_EXPENSES = 'budgetExpenses';
    case UNCATEGORIZED_EXPENSES = 'uncategorizedExpenses';
    case BUDGET_SURPLUS = 'budgetSurplus';
    case SAVINGS_TRANSFERS = 'savingsTransfers';
    case CASH_SAVINGS_RATE = 'cashSavingsRate';
    case BEGINNING_NET_WORTH = 'beginningNetWorth';
    case END_NET_WORTH = 'endNetWorth';
    case NET_WORTH_DELTA = 'netWorthDelta';

    public function metric(MonthlyProjectionView $view): MonthlyMetricView
    {
        return match ($this) {
            self::CASH_INCOME => $view->cashIncome,
            self::NON_CASH_BENEFITS => $view->nonCashBenefits,
            self::BUDGET_EXPENSES => $view->budgetExpenses,
            self::UNCATEGORIZED_EXPENSES => $view->uncategorizedExpenses,
            self::BUDGET_SURPLUS => $view->budgetSurplus,
            self::SAVINGS_TRANSFERS => $view->savingsTransfers,
            self::CASH_SAVINGS_RATE => $view->cashSavingsRate,
            self::BEGINNING_NET_WORTH => $view->beginningNetWorth,
            self::END_NET_WORTH => $view->endNetWorth,
            self::NET_WORTH_DELTA => $view->netWorthDelta,
        };
    }

    public function formula(): string
    {
        return match ($this) {
            self::CASH_INCOME => 'cash income = sum of booked INCOME transaction amounts',
            self::NON_CASH_BENEFITS => 'non-cash benefits = sum of modelled non-cash benefit sources',
            self::BUDGET_EXPENSES => 'budget expenses = negative sum of retained signed EXPENSE, FEE and REFUND amounts',
            self::UNCATEGORIZED_EXPENSES => 'uncategorized expenses = negative sum of retained unsplit signed expense amounts',
            self::BUDGET_SURPLUS => 'budget surplus = cash income - budget expenses',
            self::SAVINGS_TRANSFERS => 'savings transfers = sum of incoming transfers to SAVINGS or PORTFOLIO accounts',
            self::CASH_SAVINGS_RATE => 'cash savings rate = budget surplus / cash income',
            self::BEGINNING_NET_WORTH => 'beginning net worth = sum of eligible signed account valuations on period start',
            self::END_NET_WORTH => 'end net worth = sum of eligible signed account valuations on period end',
            self::NET_WORTH_DELTA => 'net worth delta = end net worth - beginning net worth',
        };
    }

    public function scope(): string
    {
        return match ($this) {
            self::CASH_INCOME => 'Booked, non-voided INCOME transactions in the caller workspace and month.',
            self::NON_CASH_BENEFITS => 'Non-cash benefit sources in the caller workspace and month; none are modelled in this release.',
            self::BUDGET_EXPENSES => 'Booked, non-voided EXPENSE, FEE and REFUND amounts retained by budget-included categories in the caller workspace and month.',
            self::UNCATEGORIZED_EXPENSES => 'Booked, non-voided unsplit EXPENSE, FEE and REFUND transactions in the caller workspace and month.',
            self::BUDGET_SURPLUS, self::CASH_SAVINGS_RATE => 'The exact cash-income and budget-expense views for the caller workspace and month.',
            self::SAVINGS_TRANSFERS => 'Booked incoming TRANSFER transactions to SAVINGS or PORTFOLIO accounts in the caller workspace and month.',
            self::BEGINNING_NET_WORTH => 'Eligible accounts and their latest valid valuation on the first day of the month in the caller workspace.',
            self::END_NET_WORTH => 'Eligible accounts and their latest valid valuation on the last day of the month in the caller workspace.',
            self::NET_WORTH_DELTA => 'The exact beginning and end net-worth views for the caller workspace and month.',
        };
    }
}
