import {
  createTransaction,
  createTransfer,
  listAccounts,
  type Account,
  type CreateTransactionRequest,
  type CreateTransferRequest,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import {
  accountRequestError,
  AccountRequestError,
  requestFailed,
} from '@/features/accounts/accountError';
import { AccountRulesModals } from '@/features/accounts/account-rules/AccountRulesModals';
import { AccountReconciliationModal } from '@/features/accounts/reconciliation-panel/AccountReconciliationModal';
import { TransactionEditor } from '@/features/transactions/transaction-editor/TransactionEditor';
import { TransferEditor } from '@/features/transactions/transfer-editor/TransferEditor';
import { TransactionsState } from '@/features/transactions/transactions-state/TransactionsState';
import { TransactionListFooter } from '@/features/transactions/transaction-list/TransactionListFooter';
import {
  transactionErrorKind,
  transactionRequestError,
} from '@/features/transactions/transactionError';
import { formatCalendarMonth } from '@/lib/decimal';
import { AccountIdentityCard } from './account-identity/AccountIdentityCard';
import { AccountMovementsTable } from './account-movements/AccountMovementsTable';
import { useAccountMovements } from './account-movements/useAccountMovements';
import { useAccountDetail } from './useAccountDetail';
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
      setTransactionEditorOpen(false);
      setSaved('transactionSaved');
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
      setTransferEditorOpen(false);
      setSaved('transferSaved');
      await refreshAfterWrite();
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
      <section aria-labelledby="account-detail-title" className={styles.intro}>
        <div>
          <a
            className={styles.back}
            href="/accounts"
            onClick={(event) => handleClientNavigation(event, '/accounts')}
          >
            {t('accounts.detail.back')}
          </a>
          <p>{t('accounts.detail.eyebrow')}</p>
          <h2 id="account-detail-title">{current.label}</h2>
        </div>
        <div className={styles.actions}>
          <button className="secondary-action" onClick={() => setRulesOpen(true)} type="button">
            {t('accounts.detail.actions.rules')}
          </button>
          <button
            className="secondary-action"
            disabled={current.valuation.snapshotId === null}
            onClick={() => setReconciling(true)}
            type="button"
          >
            {t('accounts.detail.actions.reconcile')}
          </button>
          <button
            className="secondary-action"
            disabled={!creatable}
            onClick={openTransferEditor}
            type="button"
          >
            {t('accounts.detail.actions.addTransfer')}
          </button>
          <button
            className="primary-action"
            disabled={!creatable}
            onClick={openTransactionEditor}
            type="button"
          >
            {t('accounts.detail.actions.addTransaction')}
          </button>
        </div>
      </section>

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

      <div className={styles.movementsHeading}>
        <h3 className={styles.movementsTitle}>{t('accounts.detail.movements.title')}</h3>
        {month !== null ? (
          <p className={styles.periodNotice}>
            {t('accounts.detail.movements.periodActive', {
              month: formatCalendarMonth(`${month}-01`, i18n.language),
            })}
            <button className="secondary-action" onClick={() => setMonth(null)} type="button">
              {t('accounts.detail.movements.clearPeriod')}
            </button>
          </p>
        ) : null}
      </div>

      {transactions.isPending ? (
        <TransactionsState
          canCreate={creatable}
          kind="loading"
          onCreate={openTransactionEditor}
          onRetry={() => void transactions.refetch()}
        />
      ) : transactions.isError && !staleCursorError ? (
        <TransactionsState
          canCreate={creatable}
          kind={movementsUnauthorized ? 'unauthorized' : 'error'}
          onCreate={openTransactionEditor}
          onRetry={() => void transactions.refetch()}
        />
      ) : items.length === 0 ? (
        <TransactionsState
          canCreate={creatable}
          kind="empty"
          onCreate={openTransactionEditor}
          onRetry={() => void transactions.refetch()}
        />
      ) : (
        <>
          <AccountMovementsTable transactions={items} />
          <TransactionListFooter
            hasMore={!staleCursorError && Boolean(displayedPage?.nextCursor)}
            loadingMore={transactions.isFetching && !transactions.isPending}
            onLoadMore={loadMore}
            onReloadFromFirstPage={reloadFromFirstPage}
            stale={staleCursorError}
          />
        </>
      )}
    </div>
  );
}
