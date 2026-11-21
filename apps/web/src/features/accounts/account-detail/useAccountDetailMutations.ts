import {
  createTransaction,
  createTransfer,
  type CreateTransactionRequest,
  type CreateTransferRequest,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { invalidateAccountBalances } from '@/features/accounts/invalidateAccountBalances';
import { idempotentApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { transactionRequestError } from '@/features/transactions/transactionError';
import { useSubmissionKey } from '@/hooks/use-submission-key';

/**
 * The two writes a detail page can trigger for its account: a prefilled
 * transaction and a transfer. Both refresh the account, its movements and the shared balance
 * and report queries, so that
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

  // One key owner per opened create modal; the ledger releases it when the modal closes.
  const transactionDraft = useSubmissionKey();
  const transferDraft = useSubmissionKey();

  /** Follow-up reads: a failure here must not surface as a failed, retryable write. */
  async function refreshAfterWrite() {
    try {
      await queryClient.invalidateQueries({ queryKey: ['account', accountId] });
      await reloadAfterWrite();
      await invalidateAccountBalances(queryClient);
    } catch {
      // The write is committed; the reads refresh on their next trigger.
    }
  }

  const saveTransaction = useMutation({
    mutationFn: async (body: CreateTransactionRequest) => {
      const key = transactionDraft.keyFor(body);
      const result = await withCsrfRetry(() =>
        createTransaction({ ...idempotentApiOptions(key), body }),
      );
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      transactionDraft.release();
      onTransactionSaved();
      await refreshAfterWrite();
    },
  });

  const saveTransfer = useMutation({
    mutationFn: async (body: CreateTransferRequest) => {
      const key = transferDraft.keyFor(body);
      const result = await withCsrfRetry(() =>
        createTransfer({ ...idempotentApiOptions(key), body }),
      );
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      transferDraft.release();
      await refreshAfterWrite();
    },
  });

  return {
    transactionDraft,
    transferDraft,
    saveTransaction,
    saveTransfer,
    completeTransfer: async () => {
      onTransferSaved();
      await invalidateAccountBalances(queryClient);
    },
  };
}
