import { listAccounts } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { transactionRequestError } from '@/features/transactions/transactionError';

/** Accounts that accept a new transaction or transfer; shared by the page and its modals. */
export function useActiveBudgetAccounts(enabled: boolean) {
  return useQuery({
    enabled,
    queryKey: ['monthly-budget-accounts'],
    queryFn: async ({ signal }) => {
      const result = await listAccounts({
        ...authApiOptions(),
        query: { includeArchived: false, includeClosed: false, page: 1, perPage: 100 },
        signal,
      });
      if (!result.response?.ok || !result.data) throw transactionRequestError(result);
      return result.data.items.filter((account) => account.status === 'ACTIVE');
    },
    retry: false,
  });
}
