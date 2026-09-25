import { listAccounts, type Account, type CreateTransactionRequest } from '@cadran/api-client';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import {
  accountRequestError,
  AccountRequestError,
  requestFailed,
} from '@/features/accounts/accountError';
import { AccountRulesModals } from '@/features/accounts/account-rules/AccountRulesModals';
import { AccountReconciliationModal } from '@/features/accounts/reconciliation-panel/AccountReconciliationModal';
import { TransactionEditor } from '@/features/transactions/transaction-editor/TransactionEditor';
import { TransferEditor } from '@/features/transactions/transfer-editor/TransferEditor';
import { transactionErrorKind } from '@/features/transactions/transactionError';
import { AccountDetailHeader } from './account-detail-header/AccountDetailHeader';
import { AccountIdentityCard } from './account-identity/AccountIdentityCard';
import { AccountMovementsSection } from './account-movements-section/AccountMovementsSection';
import { useAccountMovements } from './account-movements/useAccountMovements';
import { useAccountDetail } from './useAccountDetail';
import { useAccountDetailMutations } from './useAccountDetailMutations';
import styles from './AccountDetailPage.module.css';

type Saved = 'transactionSaved' | 'transferSaved' | 'claimed' | 'withdrawn' | 'reconciled' | null;

function initialMonthFromLocation(): string | null {
  const month = new URLSearchParams(window.location.search).get('month');
  return month && /^\d{4}-\d{2}$/.test(month) ? month : null;
}

interface AccountDetailPageProps {
  accountId: string;
}

/**
 * The generic detail page of one account: identity, dated value, applicable
 * rules and its own movements, with creation of a prefilled transaction or
 * transfer. Every read here is scoped to this one account, and every write
 * either targets it directly or is refused with the reason the shared forms
 * already carry (a closed period, a stale version).
 */
export function AccountDetailPage({ accountId }: AccountDetailPageProps) {
  const { i18n, t } = useTranslation();
  const queryClient = useQueryClient();
  const [month, setMonth] = useState(initialMonthFromLocation);
  const [transactionEditorOpen, setTransactionEditorOpen] = useState(false);
  const [transferEditorOpen, setTransferEditorOpen] = useState(false);
  const [rulesOpen, setRulesOpen] = useState(false);
  const [reconciling, setReconciling] = useState(false);
  const [saved, setSaved] = useState<Saved>(null);

  const account = useAccountDetail(accountId);
  const movements = useAccountMovements(accountId, month);
  const {
    displayedPage,
    items,
    loadMore,
    reloadAfterWrite,
    reloadFromFirstPage,
    staleCursorError,
    transactions,
    unauthorized: movementsUnauthorized,
  } = movements;

  const activeAccounts = useQuery({
    enabled: transferEditorOpen,
    queryKey: ['accounts', 'account-detail-transfer'],
    queryFn: async ({ signal }) => {
      const result = await listAccounts({
        ...authApiOptions(),
        query: { includeArchived: false, includeClosed: false, page: 1, perPage: 100 },
        signal,
      });
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data;
    },
    retry: false,
  });

  const { saveTransaction, saveTransfer } = useAccountDetailMutations({
    accountId,
    reloadAfterWrite,
    onTransactionSaved: () => {
      setTransactionEditorOpen(false);
      setSaved('transactionSaved');
    },
    onTransferSaved: () => {
      setTransferEditorOpen(false);
      setSaved('transferSaved');
    },
  });

  function closeTransactionEditor() {
    setTransactionEditorOpen(false);
    saveTransaction.reset();
  }

  function closeTransferEditor() {
    setTransferEditorOpen(false);
    saveTransfer.reset();
  }

  function openTransactionEditor() {
    setSaved(null);
    saveTransaction.reset();
    setTransactionEditorOpen(true);
  }

  function openTransferEditor() {
    setSaved(null);
    saveTransfer.reset();
    setTransferEditorOpen(true);
  }

  if (account.isPending) {
    return (
      <section aria-busy="true" className={`card ${styles.routeState}`} role="status">
        <h2>{t('accounts.detail.loading')}</h2>
      </section>
    );
  }

  if (account.isError) {
    const failure = account.error instanceof AccountRequestError ? account.error : null;
    const notFound = failure?.status === 404 || failure?.status === 403;
    const key = notFound ? 'notFound' : failure?.status === 401 ? 'unauthorized' : 'error';

    return (
      <section className={`card ${styles.routeState}`} role="alert">
        <h2>{t(`accounts.detail.${key}.title`)}</h2>
        <p>{t(`accounts.detail.${key}.description`)}</p>
        {key === 'error' ? (
          <button className="secondary-action" onClick={() => void account.refetch()} type="button">
            {t('foundation.retry')}
          </button>
        ) : null}
      </section>
    );
  }

  const current: Account = account.data;
  const creatable = current.status === 'ACTIVE';
  const transferAccounts = [
    current,
    ...(activeAccounts.data?.items ?? []).filter((item) => item.id !== current.id),
  ];

  return (
    <div className={styles.page}>
      <AccountDetailHeader
        account={current}
        creatable={creatable}
        onAddTransaction={openTransactionEditor}
        onAddTransfer={openTransferEditor}
        onOpenRules={() => setRulesOpen(true)}
        onReconcile={() => setReconciling(true)}
      />

      {!creatable && current.status !== 'ACTIVE' ? (
        <p className={styles.notice} role="status">
          {t(`accounts.detail.actions.unavailable.${current.status}`)}
        </p>
      ) : null}

      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>{t(`accounts.detail.toasts.${saved}`)}</Toast>
      ) : null}

      <AccountIdentityCard account={current} />

      {rulesOpen ? (
        <AccountRulesModals
          account={current}
          close={() => setRulesOpen(false)}
          onOverrideRecorded={() => setSaved('claimed')}
          onOverrideWithdrawn={() => setSaved('withdrawn')}
        />
      ) : null}

      {reconciling ? (
        <AccountReconciliationModal
          account={current}
          close={() => setReconciling(false)}
          onReconciled={async () => {
            setReconciling(false);
            setSaved('reconciled');
            await queryClient.invalidateQueries({ queryKey: ['account', accountId] });
          }}
        />
      ) : null}

      {transactionEditorOpen ? (
        <TransactionEditor
          accounts={[current]}
          close={closeTransactionEditor}
          onSubmit={(body) => saveTransaction.mutate(body as CreateTransactionRequest)}
          pending={saveTransaction.isPending}
          submitError={transactionErrorKind(saveTransaction.error, saveTransaction.isError)}
        />
      ) : null}

      {transferEditorOpen ? (
        <TransferEditor
          accounts={transferAccounts}
          close={closeTransferEditor}
          onSubmit={(body) => saveTransfer.mutate(body)}
          pending={saveTransfer.isPending}
          submitError={transactionErrorKind(saveTransfer.error, saveTransfer.isError)}
        />
      ) : null}

      <AccountMovementsSection
        canCreate={creatable}
        displayedPageHasMore={Boolean(displayedPage?.nextCursor)}
        items={items}
        language={i18n.language}
        loadingMore={transactions.isFetching && !transactions.isPending}
        month={month}
        onClearMonth={() => setMonth(null)}
        onCreate={openTransactionEditor}
        onLoadMore={loadMore}
        onReloadFromFirstPage={reloadFromFirstPage}
        staleCursorError={staleCursorError}
        transactions={transactions}
        unauthorized={movementsUnauthorized}
      />
    </div>
  );
}
