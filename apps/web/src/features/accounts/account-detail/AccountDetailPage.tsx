import { useAccountHeader } from '@/features/accounts/account-header/useAccountHeader';
import { AccountNavigation, accountOperationsActive } from './AccountNavigation';
import type { Account } from '@cadran/api-client';
import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AccountDetailPageSkeleton } from './AccountDetailPageSkeleton';
import { Toast } from '@/components/ui/toast/Toast';
import { AccountRequestError } from '@/features/accounts/accountError';
import { AccountRulesModals } from '@/features/accounts/account-rules/AccountRulesModals';
import { AccountReconciliationModal } from '@/features/accounts/reconciliation-panel/AccountReconciliationModal';
import { portfolioAccountHref, valuedByPortfolio } from '@/features/portfolio/portfolioAccountHref';
import { AccountDetailActions } from './account-detail-actions/AccountDetailActions';
import { AccountIdentityCard } from './account-identity/AccountIdentityCard';
import { AccountLedger, type LedgerEditor } from './account-ledger/AccountLedger';
import { SavingsHistoryPanel } from './savings-history/SavingsHistoryPanel';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { workspaceToday } from '@/lib/workspaceTime';
import { useAccountDetail } from './useAccountDetail';
import styles from './AccountDetailPage.module.css';
import { useBreadcrumbAccount } from '@/components/layout/breadcrumb/BreadcrumbContext';

type Saved = 'transactionSaved' | 'transferSaved' | 'claimed' | 'withdrawn' | 'reconciled' | null;

interface AccountDetailPageProps {
  accountId: string;
}

/**
 * The generic detail page of one account: identity, dated value, applicable
 * rules and its own movements, with creation of a prefilled transaction or
 * transfer. Every read here is scoped to this one account, and every write
 * either targets it directly or is refused with the reason the shared forms
 * already carry (a closed period, a stale version). An account valued by its
 * portfolio redirects to the account tab of its portfolio page, which shows the
 * same identity, rules and movements next to its operations.
 */
export function AccountDetailPage({ accountId }: AccountDetailPageProps) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const today = workspaceToday(new Date(), useWorkspaceTimeZone());
  const [operations, setOperations] = useState(accountOperationsActive);
  useEffect(() => {
    const update = () => setOperations(accountOperationsActive());
    window.addEventListener('popstate', update);
    return () => window.removeEventListener('popstate', update);
  }, []);
  const [editor, setEditor] = useState<LedgerEditor>(null);
  const [rulesOpen, setRulesOpen] = useState(false);
  const [reconciling, setReconciling] = useState(false);
  const [saved, setSaved] = useState<Saved>(null);

  const account = useAccountDetail(accountId);
  useAccountHeader(`/accounts/${accountId}`, account.isError ? undefined : account.data);
  useBreadcrumbAccount(
    `/accounts/${accountId}`,
    !account.isError && account.data?.id === accountId ? account.data.label : null,
    account.isPending,
  );
  const redirect =
    account.data?.id === accountId && valuedByPortfolio(account.data)
      ? portfolioAccountHref(accountId, new URLSearchParams(window.location.search).get('month'))
      : null;

  useEffect(() => {
    if (redirect === null) return;
    window.history.replaceState(window.history.state, '', redirect);
    window.dispatchEvent(new PopStateEvent('popstate'));
  }, [redirect]);

  function openEditor(next: LedgerEditor) {
    setSaved(null);
    setEditor(next);
  }

  if (account.isPending || redirect !== null) {
    return (
      <AccountDetailPageSkeleton accountId={accountId} status={t('accounts.detail.loading')} />
    );
  }

  const failure = account.error instanceof AccountRequestError ? account.error : null;
  const accessDenied = failure !== null && [401, 403, 404].includes(failure.status);

  if (!account.data || (account.isError && accessDenied)) {
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
  const creatable = current.status === 'ACTIVE' && !account.isError;

  return (
    <div className={styles.page}>
      <AccountNavigation accountId={accountId} operations={operations} />
      {account.isError && editor !== 'transfer' ? (
        <div className={styles.notice} role="alert">
          <p>{t('accounts.detail.refreshError')}</p>
          <button className="secondary-action" onClick={() => void account.refetch()} type="button">
            {t('foundation.retry')}
          </button>
        </div>
      ) : null}
      <AccountDetailActions
        account={current}
        creatable={creatable}
        onAddTransaction={() => openEditor('transaction')}
        onAddTransfer={() => openEditor('transfer')}
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

      {!operations ? <AccountIdentityCard account={current} /> : null}
      {!operations && (current.kind === 'SAVINGS' || current.kind === 'CURRENT') ? (
        <SavingsHistoryPanel accountId={accountId} today={today} />
      ) : null}

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

      {operations || editor ? (
        <AccountLedger
          showMovements={operations}
          account={current}
          creatable={creatable}
          editor={editor}
          onEditorChange={setEditor}
          onSaved={setSaved}
          refreshError={
            account.isError
              ? { message: t('accounts.detail.refreshError'), retry: () => void account.refetch() }
              : undefined
          }
        />
      ) : null}
    </div>
  );
}
