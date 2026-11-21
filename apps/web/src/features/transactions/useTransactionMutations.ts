import {
  createTransaction,
  createTransfer,
  duplicateTransaction,
  updateTransaction,
  voidTransaction,
  type CreateTransactionRequest,
  type CreateTransferRequest,
  type Transaction,
  type UpdateTransactionRequest,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { authApiOptions, idempotentApiOptions } from '@/features/auth/apiOptions';
import { useSubmissionKey } from '@/hooks/use-submission-key';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { invalidateAccountBalances } from '@/features/accounts/invalidateAccountBalances';
import type { Editor, Saved } from './transactionEditorState';
import {
  TransactionRequestError,
  transactionErrorKind,
  transactionRequestError,
} from './transactionError';

interface UseTransactionMutationsOptions {
  /** The editor the create/edit mutation is currently bound to: `null` picks the create call. */
  editor: Editor;
  onSaved: () => void;
  onTransferSaved: () => void;
  onVoided: () => void;
  /** Reloads the first page; shared with the listing so a write and a stale reload agree. */
  reloadAfterWrite: () => Promise<void>;
}

/**
 * The four write operations the transactions screen offers - create or edit, transfer, void
 * and duplicate - and the toast and double-submission guard they share. Isolated from the
 * page's layout so each mutation's success and error handling is readable on its own.
 */
export function useTransactionMutations({
  editor,
  onSaved,
  onTransferSaved,
  onVoided,
  reloadAfterWrite,
}: UseTransactionMutationsOptions) {
  const queryClient = useQueryClient();
  const [saved, setSaved] = useState<Saved>(null);
  const duplicatingIdsRef = useRef(new Set<string>());
  const [duplicatingIds, setDuplicatingIds] = useState<ReadonlySet<string>>(new Set());

  async function refreshOnStaleTransaction(error: unknown) {
    if (error instanceof TransactionRequestError && error.kind === 'stale') {
      await reloadAfterWrite();
    }
  }

  // One key owner per create draft: the page releases it when it opens or closes the modal.
  const transactionDraft = useSubmissionKey();
  const transferDraft = useSubmissionKey();

  /** Follow-up reads: a failure here must not surface as a failed, retryable write. */
  async function refreshReads() {
    try {
      await reloadAfterWrite();
      await invalidateAccountBalances(queryClient);
    } catch {
      // The write is committed; the reads refresh on their next trigger.
    }
  }

  const save = useMutation({
    mutationFn: async (body: CreateTransactionRequest | UpdateTransactionRequest) => {
      const result =
        editor && editor !== 'create'
          ? await withCsrfRetry(() =>
              updateTransaction({
                ...authApiOptions(),
                path: { id: editor.id },
                body: body as UpdateTransactionRequest,
              }),
            )
          : await createWithKey(body as CreateTransactionRequest);
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      // Committed: end the draft before any read, so a failed refresh cannot become a second write.
      if (!editor || editor === 'create') transactionDraft.release();
      onSaved();
      setSaved('saved');
      await refreshReads();
    },
    onError: refreshOnStaleTransaction,
  });

  function createWithKey(body: CreateTransactionRequest) {
    const key = transactionDraft.keyFor(body);

    return withCsrfRetry(() => createTransaction({ ...idempotentApiOptions(key), body }));
  }

  const transferSave = useMutation({
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
      await refreshReads();
    },
  });

  const voidMutation = useMutation({
    mutationFn: async (transaction: Transaction) => {
      const result = await withCsrfRetry(() =>
        voidTransaction({
          ...authApiOptions(),
          path: { id: transaction.id },
          body: { version: transaction.version },
        }),
      );
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    onError: refreshOnStaleTransaction,
    onSuccess: async () => {
      await reloadAfterWrite();
      await invalidateAccountBalances(queryClient);
      onVoided();
      setSaved('voided');
    },
  });

  const duplicate = useMutation({
    mutationFn: async (transaction: Transaction) => {
      const result = await withCsrfRetry(() =>
        duplicateTransaction({
          ...authApiOptions(),
          path: { id: transaction.id },
        }),
      );
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      setSaved('duplicated');
      await reloadAfterWrite();
      await invalidateAccountBalances(queryClient);
    },
    onSettled: (_data, _error, transaction) => {
      duplicatingIdsRef.current.delete(transaction.id);
      setDuplicatingIds(new Set(duplicatingIdsRef.current));
    },
  });

  function duplicateOnce(transaction: Transaction) {
    if (duplicatingIdsRef.current.has(transaction.id)) {
      return;
    }

    duplicatingIdsRef.current.add(transaction.id);
    setDuplicatingIds(new Set(duplicatingIdsRef.current));
    duplicate.mutate(transaction);
  }

  return {
    duplicate,
    duplicateError: transactionErrorKind(duplicate.error, duplicate.isError),
    duplicateOnce,
    duplicatingIds,
    save,
    saved,
    setSaved,
    submitError: transactionErrorKind(save.error, save.isError),
    transactionDraft,
    transferDraft,
    transferSave,
    completeTransfer: () => {
      onTransferSaved();
      setSaved('transferSaved');
    },
    voidError: transactionErrorKind(voidMutation.error, voidMutation.isError),
    voidMutation,
  };
}
