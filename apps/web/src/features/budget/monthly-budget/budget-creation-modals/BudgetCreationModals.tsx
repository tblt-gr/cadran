import {
  createTransaction,
  createTransfer,
  type CreateTransactionRequest,
  type CreateTransferRequest,
  type MonthlyLedgerAccountRow,
  type MonthlyLedgerCategoryRow,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { invalidateAccountBalances } from '@/features/accounts/invalidateAccountBalances';
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
import { budgetTransactionDefaults } from './budgetCreationDefaults';
import { BudgetCreationSkeleton } from './BudgetCreationSkeleton';

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
  const queryClient = useQueryClient();
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
      await invalidateAccountBalances(queryClient);
    },
  });
  const transfer = useMutation({
    mutationFn: async (body: CreateTransferRequest) => {
      const result = await withCsrfRetry(() => createTransfer({ ...authApiOptions(), body }));
      if (!result.response?.ok || !result.data) throw transactionRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      await onSaved();
      await invalidateAccountBalances(queryClient);
    },
  });

  if (!draft) return null;

  const bookedOn = defaultDayForMonth(month, today);

  if (accounts.isPending) {
    return <BudgetCreationSkeleton bookedOn={bookedOn} close={close} draft={draft} />;
  }

  if (accounts.isError) {
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
      </Modal>
    );
  }

  if (draft.kind === 'account') {
    return (
      <TransferEditor
        accounts={accounts.data}
        close={close}
        defaults={{ bookedOn, requireSourceChoice: true, targetAccountId: draft.row.id }}
        onSubmit={(body) => transfer.mutateAsync(body)}
        onSaved={close}
        pending={transfer.isPending}
        submitError={transactionErrorKind(transfer.error, transfer.isError)}
      />
    );
  }

  return (
    <TransactionEditor
      accounts={accounts.data}
      close={close}
      defaults={budgetTransactionDefaults(draft, bookedOn)}
      onSubmit={(body) => transaction.mutate(body as CreateTransactionRequest)}
      pending={transaction.isPending}
      submitError={transactionErrorKind(transaction.error, transaction.isError)}
    />
  );
}
