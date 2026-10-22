import { moveAccount, type Account } from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';

export interface AccountMove {
  account: Account;
  beforeAccountId: string | null;
  groupId: string | null;
  orderVersion: number;
}

/**
 * Saves one reorder step. Nothing is optimistic: the table keeps the last saved
 * order until the refetch after the write, so a refused move leaves it intact.
 */
export function useMoveAccount(onMoved: (account: Account) => void) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ account, beforeAccountId, groupId, orderVersion }: AccountMove) => {
      const result = await withCsrfRetry(() =>
        moveAccount({
          ...authApiOptions(),
          path: { id: account.id },
          body: { beforeAccountId, groupId, orderVersion },
        }),
      );
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data!;
    },
    onSuccess: async (_moved, variables) => {
      await queryClient.invalidateQueries({ queryKey: ['accounts'] });
      onMoved(variables.account);
    },
  });
}
