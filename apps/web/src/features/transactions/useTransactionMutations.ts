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
import { useMutation } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
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
 * The four write operations the transactions screen offers — create or edit, transfer, void
 * and duplicate — and the toast and double-submission guard they share. Isolated from the
 * page's layout so each mutation's success and error handling is readable on its own.
 */
export function useTransactionMutations({
  editor,
  onSaved,
  onTransferSaved,
  onVoided,
  reloadAfterWrite,
}: UseTransactionMutationsOptions) {
  const [saved, setSaved] = useState<Saved>(null);
  const duplicatingIdsRef = useRef(new Set<string>());
  const [duplicatingIds, setDuplicatingIds] = useState<ReadonlySet<string>>(new Set());

  async function refreshOnStaleTransaction(error: unknown) {
    if (error instanceof TransactionRequestError && error.kind === 'stale') {
      await reloadAfterWrite();
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
          : await withCsrfRetry(() =>
              createTransaction({ ...authApiOptions(), body: body as CreateTransactionRequest }),
            );
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      onSaved();
      setSaved('saved');
      await reloadAfterWrite();
    },
    onError: refreshOnStaleTransaction,
  });

  const transferSave = useMutation({
    mutationFn: async (body: CreateTransferRequest) => {
      const result = await withCsrfRetry(() => createTransfer({ ...authApiOptions(), body }));
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      onTransferSaved();
      setSaved('transferSaved');
      await reloadAfterWrite();
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
      onVoided();
      setSaved('voided');
      await reloadAfterWrite();
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
    transferSave,
    voidError: transactionErrorKind(voidMutation.error, voidMutation.isError),
    voidMutation,
  };
}
