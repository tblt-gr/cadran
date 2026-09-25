import {
  createTransaction,
  createTransfer,
  type CreateTransactionRequest,
  type CreateTransferRequest,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { transactionRequestError } from '@/features/transactions/transactionError';

/**
 * The two writes a detail page can trigger for its account: a prefilled
 * transaction and a transfer. Both invalidate the same pair of reads (the
 * account itself, for its refreshed valuation, and its movements), so that
 * invalidation is composed once here rather than duplicated per mutation.
 */
export function useAccountDetailMutations({
  accountId,
  reloadAfterWrite,
  onTransactionSaved,
  onTransferSaved,
}: {
  accountId: string;
  reloadAfterWrite: () => Promise<void>;
  onTransactionSaved: () => void;
  onTransferSaved: () => void;
}) {
  const queryClient = useQueryClient();

  async function refreshAfterWrite() {
    await queryClient.invalidateQueries({ queryKey: ['account', accountId] });
    await reloadAfterWrite();
  }

  const saveTransaction = useMutation({
    mutationFn: async (body: CreateTransactionRequest) => {
      const result = await withCsrfRetry(() => createTransaction({ ...authApiOptions(), body }));
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      onTransactionSaved();
      await refreshAfterWrite();
    },
  });

  const saveTransfer = useMutation({
    mutationFn: async (body: CreateTransferRequest) => {
      const result = await withCsrfRetry(() => createTransfer({ ...authApiOptions(), body }));
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      onTransferSaved();
      await refreshAfterWrite();
    },
  });

  return { saveTransaction, saveTransfer };
}
