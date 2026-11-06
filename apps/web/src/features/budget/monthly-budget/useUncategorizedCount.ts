import { countTransactions } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import {
  DEFAULT_FILTERS,
  toListTransactionsQuery,
} from '@/features/transactions/transaction-filters/filterState';
import { transactionRequestError } from '@/features/transactions/transactionError';

/** Query key prefix of the reminder count, invalidated with the month's other queries. */
export const uncategorizedCountKey = (month: string) =>
  ['transactions-count', 'uncategorized', month] as const;

/**
 * Server count of the month's uncategorized transactions, with the very filters of the list the
 * reminder links to. Null while loading or on error: the reminder then renders nothing.
 */
export function useUncategorizedCount(month: string | null): number | null {
  const count = useQuery({
    queryKey: uncategorizedCountKey(month ?? ''),
    queryFn: async ({ signal }) => {
      const query = toListTransactionsQuery(
        { ...DEFAULT_FILTERS, period: 'month', month: month!, categorization: 'NONE' },
        new Date(),
      );
      const result = await countTransactions({ ...authApiOptions(), query, signal });
      if (!result.response?.ok || !result.data) throw transactionRequestError(result);
      return result.data.count;
    },
    enabled: month !== null,
    retry: false,
  });

  return count.data ?? null;
}
