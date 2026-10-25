import { useQuery } from '@tanstack/react-query';
import { listActiveAccounts } from '@/features/transactions/transactionAccounts';

/** Accounts that accept a new transaction or transfer; shared by the page and its modals. */
export function useActiveBudgetAccounts(enabled: boolean) {
  return useQuery({
    enabled,
    queryKey: ['accounts', 'monthly-budget'],
    queryFn: async ({ signal }) => {
      const result = await listActiveAccounts(signal);
      return result.items.filter((account) => account.status === 'ACTIVE');
    },
    retry: false,
  });
}
