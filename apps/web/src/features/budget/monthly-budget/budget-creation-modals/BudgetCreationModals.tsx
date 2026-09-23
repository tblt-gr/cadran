import {
  createTransaction,
  createTransfer,
  type CreateTransactionRequest,
  type CreateTransferRequest,
  type MonthlyLedgerAccountRow,
  type MonthlyLedgerCategoryRow,
} from '@cadran/api-client';
import { useMutation } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { TransactionEditor } from '@/features/transactions/transaction-editor/TransactionEditor';
import {
  transactionErrorKind,
  transactionRequestError,
} from '@/features/transactions/transactionError';
import { TransferEditor } from '@/features/transactions/transfer-editor/TransferEditor';
import { defaultDayForMonth } from '@/features/budget/monthly-budget/budgetPeriod';
import { useActiveBudgetAccounts } from './useActiveBudgetAccounts';

export type BudgetCreationDraft =
  /** A `null` row opens the form with no category, chosen inside the modal. */
  | { kind: 'income' | 'expense'; row: MonthlyLedgerCategoryRow | null }
  | { kind: 'account'; row: MonthlyLedgerAccountRow }
  | null;

interface BudgetCreationModalsProps {
  close: () => void;
  draft: BudgetCreationDraft;
  month: string;
  onSaved: () => Promise<void>;
  today: string;
}

export function BudgetCreationModals({
  close,
  draft,
  month,
  onSaved,
  today,
}: BudgetCreationModalsProps) {
  const { t } = useTranslation();
  const accounts = useActiveBudgetAccounts(draft !== null);
  const transaction = useMutation({
    mutationFn: async (body: CreateTransactionRequest) => {
      const result = await withCsrfRetry(() => createTransaction({ ...authApiOptions(), body }));
      if (!result.response?.ok || !result.data) throw transactionRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      close();
      await onSaved();
    },
  });
  const transfer = useMutation({
    mutationFn: async (body: CreateTransferRequest) => {
      const result = await withCsrfRetry(() => createTransfer({ ...authApiOptions(), body }));
      if (!result.response?.ok || !result.data) throw transactionRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      close();
      await onSaved();
    },
  });

  if (!draft) return null;

  if (accounts.isPending || accounts.isError) {
    return (
      <Modal
        close={close}
        eyebrow={t('transactions.form.createEyebrow')}
        title={t(
          draft.kind === 'account'
            ? 'transactions.transfer.title'
            : 'transactions.form.createTitle',
        )}
      >
        {accounts.isPending ? (
          <p aria-live="polite">{t('budget.monthly.accountsLoading')}</p>
        ) : (
          <div role="alert">
            <p>{t('budget.monthly.accountsError')}</p>
            <button
              className="secondary-action"
              onClick={() => void accounts.refetch()}
              type="button"
            >
              {t('foundation.retry')}
            </button>
          </div>
        )}
      </Modal>
    );
  }

  const bookedOn = defaultDayForMonth(month, today);

  if (draft.kind === 'account') {
    return (
      <TransferEditor
        accounts={accounts.data}
        close={close}
        defaults={{ bookedOn, requireSourceChoice: true, targetAccountId: draft.row.id }}
        onSubmit={(body) => transfer.mutate(body)}
        pending={transfer.isPending}
        submitError={transactionErrorKind(transfer.error, transfer.isError)}
      />
    );
  }

  return (
    <TransactionEditor
      accounts={accounts.data}
      close={close}
      defaults={{
        bookedOn,
        category: {
          color: draft.row?.color ?? null,
          icon: draft.row?.icon ?? null,
          id: draft.row?.id ?? '',
          label: draft.row?.label ?? '',
          type: draft.kind === 'income' ? 'INCOME' : 'EXPENSE',
        },
        nature: draft.kind === 'income' ? 'INCOME' : 'EXPENSE',
        requireAccountChoice: true,
      }}
      onSubmit={(body) => transaction.mutate(body as CreateTransactionRequest)}
      pending={transaction.isPending}
      submitError={transactionErrorKind(transaction.error, transaction.isError)}
    />
  );
}
