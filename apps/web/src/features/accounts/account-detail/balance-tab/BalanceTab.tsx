import type { Account, AccountBalanceSnapshot } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useArrivalAction } from '@/features/accounts/account-actions-menu/useArrivalAction';
import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';
import { useBalanceSnapshots } from '@/features/accounts/record-balance-form/balance-snapshots/useBalanceSnapshots';
import { useBalanceWithdrawal } from '@/features/accounts/record-balance-form/balance-snapshots/useBalanceWithdrawal';
import { BalanceTable } from './BalanceTable';
import { RecordBalanceModal } from './RecordBalanceModal';
import styles from './BalanceTab.module.css';

type Dialog = { correcting?: AccountBalanceSnapshot; asOf?: string } | null;

/**
 * The Solde tab of a balance-valued account: every recorded balance, newest first, with the
 * record, correct and withdraw actions. Reads the existing balances endpoints and adds no figure.
 */
export function BalanceTab({ account }: { account: Account }) {
  const { t } = useTranslation();
  const snapshots = useBalanceSnapshots(account.id);
  const [dialog, setDialog] = useState<Dialog>(null);
  const items = snapshots.data?.items ?? null;
  const total = snapshots.data?.total ?? 0;
  const withdrawal = useBalanceWithdrawal(account.id, items);
  const recordable = account.status === 'ACTIVE';
  const empty = items !== null && items.length === 0;
  // The closure modal links here to record the statement balance of a month end.
  useArrivalAction(
    'record-balance',
    true,
    ({ asOf }) => {
      if (account.status === 'ACTIVE' && account.editable) setDialog(asOf ? { asOf } : {});
    },
    { consumesAsOf: true },
  );
  const record = (
    <button
      className="secondary-action"
      disabled={!recordable}
      onClick={() => setDialog({})}
      type="button"
    >
      {t('accounts.balanceTab.record')}
    </button>
  );

  return (
    <div className={styles.tab}>
      <div className={styles.toolbar}>
        <h2>{t('accounts.balanceTab.navLabel')}</h2>
        {empty ? null : record}
      </div>
      {!recordable ? (
        <p className={styles.notice}>{t('accounts.balanceTab.recordUnavailable')}</p>
      ) : null}
      {withdrawal.error ? (
        <p className={styles.alert} role="alert">
          {t(`accounts.balances.errors.${withdrawal.error}`)}
        </p>
      ) : null}
      {withdrawal.confirmingId !== null ? (
        <p className={styles.notice} id={withdrawal.hintId}>
          {t('accounts.snapshots.confirmHint')}
        </p>
      ) : null}
      {snapshots.isPending ? (
        <BalanceTable items={null} loadingStatus={t('accounts.balanceTab.loading')} />
      ) : snapshots.isError ? (
        <OperationsState error title={t('accounts.balanceTab.errorTitle')}>
          <p>{t('accounts.snapshots.error')}</p>
          <button
            className="secondary-action"
            onClick={() => void snapshots.refetch()}
            type="button"
          >
            {t('foundation.retry')}
          </button>
        </OperationsState>
      ) : items!.length === 0 ? (
        <OperationsState title={t('accounts.balanceTab.emptyTitle')}>
          <p>{t('accounts.balanceTab.emptyDescription')}</p>
          {record}
        </OperationsState>
      ) : (
        <>
          <BalanceTable
            items={items}
            onEdit={(snapshot) => setDialog({ correcting: snapshot })}
            withdrawal={withdrawal}
          />
          {total > items!.length ? (
            <p className={styles.notice}>
              {t('accounts.snapshots.truncated', { shown: items!.length, total })}
            </p>
          ) : null}
        </>
      )}
      {dialog ? (
        <RecordBalanceModal
          account={account}
          close={() => setDialog(null)}
          correcting={dialog.correcting}
          initialAsOf={dialog.asOf}
        />
      ) : null}
    </div>
  );
}
