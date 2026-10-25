import type { ExplainMonthlyKpiData } from '@cadran/api-client';

export type RecapKpi = Extract<
  ExplainMonthlyKpiData['path']['kpi'],
  | 'cashIncome'
  | 'nonCashBenefits'
  | 'benefitSpending'
  | 'budgetExpenses'
  | 'uncategorizedExpenses'
  | 'budgetSurplus'
  | 'savingsTransfers'
  | 'cashSavingsRate'
  | 'savingsInflows'
  | 'savingsWithdrawals'
  | 'netSavingsTransfers'
  | 'netSavingsRate'
>;

/**
 * The flow indicators of the month, in the order the former monthly report listed them. The net
 * worth ones stay with the wealth card.
 */
export const RECAP_KPIS: readonly RecapKpi[] = [
  'cashIncome',
  'nonCashBenefits',
  'benefitSpending',
  'budgetExpenses',
  'uncategorizedExpenses',
  'budgetSurplus',
  'savingsTransfers',
  'cashSavingsRate',
  'savingsInflows',
  'savingsWithdrawals',
  'netSavingsTransfers',
  'netSavingsRate',
];

export const RATE_KPIS: readonly RecapKpi[] = ['cashSavingsRate', 'netSavingsRate'];
