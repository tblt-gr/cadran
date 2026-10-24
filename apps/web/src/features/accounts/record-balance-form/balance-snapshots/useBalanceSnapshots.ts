import {
  correctAccountBalance,
  listAccountBalances,
  withdrawAccountBalance,
  type AccountBalanceSnapshot,
  type CorrectAccountBalanceRequest,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';
import { invalidateAccountBalances } from '@/features/accounts/invalidateAccountBalances';

export function useBalanceSnapshots(accountId: string) {
  return useQuery({
    queryKey: ['account-balances', accountId],
    queryFn: async ({ signal }) => {
      const result = await listAccountBalances({
        ...authApiOptions(),
        path: { id: accountId },
        query: { page: 1, pageSize: 100 },
        signal,
      });
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data!;
    },
    retry: false,
  });
}

export function useWithdrawBalance(accountId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (snapshot: AccountBalanceSnapshot) => {
      const result = await withCsrfRetry(() =>
        withdrawAccountBalance({
          ...authApiOptions(),
          path: { id: accountId, snapshotId: snapshot.id },
          body: { version: snapshot.version },
        }),
      );
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data!;
    },
    onSuccess: async () => {
      await invalidateAccountBalances(queryClient);
    },
  });
}

/** Amends a manual snapshot where it stands: same row, same date, no replacement. */
export function useCorrectBalance(accountId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({
      snapshot,
      body,
    }: {
      snapshot: AccountBalanceSnapshot;
      body: CorrectAccountBalanceRequest;
    }) => {
      const result = await withCsrfRetry(() =>
        correctAccountBalance({
          ...authApiOptions(),
          path: { id: accountId, snapshotId: snapshot.id },
          body,
        }),
      );
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data!;
    },
    // A stale or refused correction means the list on screen is out of date.
    onError: async () => {
      await queryClient.invalidateQueries({ queryKey: ['account-balances', accountId] });
    },
    onSuccess: async () => {
      await invalidateAccountBalances(queryClient);
    },
  });
}
