import type { QueryClient } from '@tanstack/react-query';

/** Movement writes also change balances, provenance and reports across account pages. */
export async function invalidateAccountBalances(queryClient: QueryClient): Promise<void> {
  await Promise.all(
    [
      'accounts',
      'account',
      'account-valuation-history',
      'account-balances',
      'account-rules',
      'net-worth',
      'net-worth-history',
      'monthly-recap',
      'monthly-report',
      'monthly-kpi-explanation',
      'annual-report-explanation',
      'period-status',
      'monthly-ledger',
      'monthly-ledger-movements',
      'budget-comparisons',
      'budget-plans',
      'budget-plan',
      'annual-report',
      'multi-year-report',
    ].map((key) => queryClient.invalidateQueries({ queryKey: [key] })),
  );
}
