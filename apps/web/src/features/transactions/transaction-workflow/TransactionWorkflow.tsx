import { readPeriodStatus, type Account, type Transaction } from '@cadran/api-client';
import { useQueries, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { invalidateAccountBalances } from '@/features/accounts/invalidateAccountBalances';
import { TransactionModals } from '@/features/transactions/transaction-modals/TransactionModals';
import type { Editor, Saved } from '@/features/transactions/transactionEditorState';
import { useTransactionMutations } from '@/features/transactions/useTransactionMutations';
import { transactionRequestError } from '@/features/transactions/transactionError';
import { TransactionActionsContext, type TransactionAction } from './transactionActions';
import { TransactionInspection } from './transaction-inspection/TransactionInspection';
import { TransferWorkflow } from './transfer-workflow/TransferWorkflow';
import { PendingReconciliation } from './pending-reconciliation/PendingReconciliation';

type Target = { action: TransactionAction; transaction: Transaction } | null;

/** Both lists compose this controller, so actions cannot bypass their shared writes or guards. */
export function TransactionWorkflow({
  accounts,
  transactions,
  reloadAfterWrite,
  children,
}: {
  accounts: Account[];
  transactions: Transaction[];
  reloadAfterWrite: () => Promise<void>;
  children: ReactNode;
}) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [target, setTarget] = useState<Target>(null);
  const returnControl = useRef<HTMLElement | null>(null);
  const editor: Editor =
    target?.action === 'edit' && target.transaction.transferId === null ? target.transaction : null;
  const months = [...new Set(transactions.map((transaction) => transaction.bookedOn.slice(0, 7)))];
  const periods = useQueries({
    queries: months.map((period) => ({
      queryKey: ['period-status', period],
      staleTime: 60_000,
      queryFn: async ({ signal }: { signal: AbortSignal }) => {
        const result = await readPeriodStatus({ ...authApiOptions(), path: { period }, signal });
        if (!result.response?.ok || !result.data) throw transactionRequestError(result);
        return result.data;
      },
      retry: false,
    })),
  });
  const closedMonths = new Set(months.filter((_, index) => periods[index]?.data?.closed));
  const restoringFocus = useRef(false);
  function close() {
    restoringFocus.current = true;
    setTarget(null);
  }
  useEffect(() => {
    if (target !== null || !restoringFocus.current) return;
    restoringFocus.current = false;
    const frame = window.requestAnimationFrame(() => {
      const control = returnControl.current;
      if (control?.isConnected && control !== document.body) control.focus();
      else document.querySelector<HTMLElement>('[data-transaction-focus-fallback]')?.focus();
    });
    return () => window.cancelAnimationFrame(frame);
  }, [target]);

  const mutations = useTransactionMutations({
    editor,
    onSaved: close,
    onVoided: close,
    onTransferSaved: close,
    reloadAfterWrite,
  });
  async function refresh(toast: Saved = null) {
    await reloadAfterWrite();
    await invalidateAccountBalances(queryClient);
    await queryClient.invalidateQueries({ queryKey: ['transaction-refundable'] });
    if (toast) mutations.setSaved(toast);
  }
  function open(action: TransactionAction, transaction: Transaction) {
    if (action === 'duplicate') {
      mutations.duplicateOnce(transaction);
      return;
    }
    // ActionMenu returns focus to its stable trigger before opening the overlay.
    returnControl.current =
      document.activeElement instanceof HTMLElement ? document.activeElement : null;
    mutations.save.reset();
    mutations.voidMutation.reset();
    setTarget({ action, transaction });
  }
  const activeAccounts = accounts.filter((account) => account.status === 'ACTIVE');
  const transfer =
    target &&
    target.transaction.transferId !== null &&
    (target.action === 'edit' || target.action === 'void')
      ? target
      : null;
  const portfolio =
    target &&
    accounts.find((account) => account.id === target.transaction.accountId)?.valuationMode ===
      'PORTFOLIO' &&
    target.transaction.transferId === null;
  return (
    <TransactionActionsContext.Provider
      value={{ accounts, closedMonths, duplicatingIds: mutations.duplicatingIds, open }}
    >
      {mutations.saved ? (
        <Toast onDismiss={() => mutations.setSaved(null)}>
          {t(`transactions.toasts.${mutations.saved}`)}
        </Toast>
      ) : null}
      {mutations.duplicateError ? (
        <p role="alert">{t(`transactions.errors.${mutations.duplicateError}`)}</p>
      ) : null}
      {children}
      <TransactionModals
        accountOptions={accounts}
        activeAccountOptions={activeAccounts}
        editor={editor}
        closeEditor={close}
        save={mutations.save}
        submitError={mutations.submitError}
        transferEditorOpen={false}
        closeTransferEditor={close}
        transferSave={mutations.transferSave}
        onTransferSaved={mutations.completeTransfer}
        voiding={
          target?.action === 'void' && !transfer && !portfolio
            ? (transactions.find((transaction) => transaction.id === target.transaction.id) ??
              target.transaction)
            : null
        }
        closeVoiding={close}
        voidError={mutations.voidError}
        voidMutation={mutations.voidMutation}
        refundTarget={target?.action === 'refund' ? target.transaction : null}
        onRefundClosed={close}
        onRefundSaved={() => refresh()}
      />
      {target?.action === 'inspect' ? (
        <TransactionInspection transactionId={target.transaction.id} close={close} />
      ) : null}
      {transfer ? (
        <TransferWorkflow
          id={transfer.transaction.transferId!}
          voiding={transfer.action === 'void'}
          close={close}
          onSaved={() => refresh(transfer.action === 'void' ? 'transferVoided' : 'transferSaved')}
        />
      ) : null}
      {target?.action === 'reconcile' ? (
        <PendingReconciliation
          transactionId={target.transaction.id}
          close={close}
          onSaved={() => refresh('reconciled')}
        />
      ) : null}
    </TransactionActionsContext.Provider>
  );
}
