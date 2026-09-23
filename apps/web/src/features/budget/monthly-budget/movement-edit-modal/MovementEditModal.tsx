import {
  getTransaction,
  updateTransaction,
  type UpdateTransactionRequest,
} from '@cadran/api-client';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { useActiveBudgetAccounts } from '@/features/budget/monthly-budget/budget-creation-modals/useActiveBudgetAccounts';
import { TransactionEditor } from '@/features/transactions/transaction-editor/TransactionEditor';
import {
  listReferencedAccounts,
  mergeAccountOptions,
} from '@/features/transactions/transactionAccounts';
import {
  TransactionRequestError,
  transactionErrorKind,
  transactionRequestError,
} from '@/features/transactions/transactionError';

interface MovementEditModalProps {
  close: () => void;
  onSaved: () => Promise<void>;
  transactionId: string;
}

/**
 * Opens the shared transaction editor on one movement of the ledger. The row is read fresh
 * (never from the ledger page, which carries no version) so a save is checked against the
 * version the user actually sees.
 */
export function MovementEditModal({ close, onSaved, transactionId }: MovementEditModalProps) {
  const { t } = useTranslation();
  const transaction = useQuery({
    gcTime: 0,
    queryKey: ['monthly-budget-movement', transactionId],
    queryFn: async ({ signal }) => {
      const result = await getTransaction({
        ...authApiOptions(),
        path: { id: transactionId },
        signal,
      });
      if (!result.response?.ok || !result.data) throw transactionRequestError(result);
      return result.data;
    },
    retry: false,
  });
  const activeAccounts = useActiveBudgetAccounts(true);
  const accountId = transaction.data?.accountId;
  const referenced = useQuery({
    enabled: accountId !== undefined,
    queryKey: ['monthly-budget-movement-account', accountId],
    queryFn: ({ signal }) => listReferencedAccounts([accountId!], signal),
    retry: false,
  });

  const save = useMutation({
    mutationFn: async (body: UpdateTransactionRequest) => {
      const result = await withCsrfRetry(() =>
        updateTransaction({ ...authApiOptions(), path: { id: transactionId }, body }),
      );
      if (!result.response?.ok || !result.data) throw transactionRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      close();
      await onSaved();
    },
    onError: async (error) => {
      if (error instanceof TransactionRequestError && error.kind === 'stale') {
        await transaction.refetch();
      }
    },
  });

  const failed = transaction.isError || activeAccounts.isError || referenced.isError;
  const ready = transaction.data && activeAccounts.data && referenced.data;

  if (!ready) {
    return (
      <Modal
        close={close}
        eyebrow={t('transactions.form.editEyebrow')}
        title={t('transactions.form.editTitle')}
      >
        {failed ? (
          <div role="alert">
            <p>{t('budget.monthly.movements.editError')}</p>
            <button
              className="secondary-action"
              onClick={() => {
                void transaction.refetch();
                void activeAccounts.refetch();
                void referenced.refetch();
              }}
              type="button"
            >
              {t('foundation.retry')}
            </button>
          </div>
        ) : (
          <p aria-live="polite">{t('budget.monthly.movements.editLoading')}</p>
        )}
      </Modal>
    );
  }

  return (
    <TransactionEditor
      accounts={mergeAccountOptions(activeAccounts.data, referenced.data)}
      close={close}
      onSubmit={(body) => save.mutate(body as UpdateTransactionRequest)}
      pending={save.isPending}
      submitError={transactionErrorKind(save.error, save.isError)}
      transaction={transaction.data}
    />
  );
}
