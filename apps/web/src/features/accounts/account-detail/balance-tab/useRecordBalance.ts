import { recordAccountBalance, type RecordAccountBalanceRequest } from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';
import { invalidateAccountBalances } from '@/features/accounts/invalidateAccountBalances';

/** Records a manual balance from the detail page and refreshes every figure that reads it. */
export function useRecordBalance(accountId: string, onRecorded: () => void) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (body: RecordAccountBalanceRequest) => {
      const result = await withCsrfRetry(() =>
        recordAccountBalance({ ...authApiOptions(), path: { id: accountId }, body }),
      );
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data;
    },
    // A stale replacement means the list on screen is out of date.
    onError: async () => {
      await queryClient.invalidateQueries({ queryKey: ['account-balances', accountId] });
    },
    onSuccess: async () => {
      onRecorded();
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['portfolios', 'overview'] }),
        invalidateAccountBalances(queryClient),
      ]);
    },
  });
}
