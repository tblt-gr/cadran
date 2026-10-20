import type { QueryClient } from '@tanstack/react-query';

/** Movement writes also change balances, provenance and reports across account pages. */
export async function invalidateAccountBalances(queryClient: QueryClient): Promise<void> {
  await Promise.all(
    [
      'accounts',
      'account',
      'account-rules',
      'net-worth',
      'net-worth-history',
      'monthly-recap',
      'annual-report',
      'multi-year-report',
    ].map((key) => queryClient.invalidateQueries({ queryKey: [key] })),
  );
}
