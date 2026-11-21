import type { AccountBalanceSnapshot } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { BalanceDayList } from '@/features/accounts/balance-day-list/BalanceDayList';
import { useBalanceSnapshots } from './useBalanceSnapshots';
import { useBalanceWithdrawal } from './useBalanceWithdrawal';
import { InsufficientBalanceMessage } from '@/features/transactions/insufficient-balance/InsufficientBalanceMessage';
import styles from './BalanceSnapshotsList.module.css';

/**
 * Every snapshot recorded on the account, newest first. Only an active one can
 * be withdrawn; a withdrawn one stays listed as such. The withdrawal is
 * confirmed in the row itself, since the list lives inside a modal. A manual one
 * can also be handed back to the form for correction: only a manual snapshot,
 * since recording replaces the active snapshot of the same day and source.
 */
export function BalanceSnapshotsList({
  accountId,
  onEdit,
}: {
  accountId: string;
  onEdit?: (snapshot: AccountBalanceSnapshot) => void;
}) {
  const { t } = useTranslation();
  const snapshots = useBalanceSnapshots(accountId);
  const pending = snapshots.isPending;
  const items = snapshots.data?.items ?? null;
  const total = snapshots.data?.total ?? 0;
  const withdrawal = useBalanceWithdrawal(accountId, items);
  const hintId = withdrawal.hintId;
  const empty = !pending && items !== null && items.length === 0;

  if (snapshots.isError) {
    return (
      <div role="alert">
        <p className={styles.alert}>{t('accounts.snapshots.error')}</p>
        <button className="secondary-action" onClick={() => void snapshots.refetch()} type="button">
          {t('foundation.retry')}
        </button>
      </div>
    );
  }

  return (
    <div aria-busy={pending}>
      <p className={styles.notice}>{t('accounts.snapshots.description')}</p>
      {total > (items?.length ?? 0) ? (
        <p className={styles.notice}>
          {t('accounts.snapshots.truncated', { shown: items?.length ?? 0, total })}
        </p>
      ) : null}
      {withdrawal.error ? (
        <p className={styles.alert} role="alert">
          {withdrawal.error === 'insufficientBalance' ? (
            <InsufficientBalanceMessage date={withdrawal.errorDay} write="withdrawal" />
          ) : (
            t(`accounts.balances.errors.${withdrawal.error}`)
          )}
        </p>
      ) : null}
      {withdrawal.confirmingId !== null ? (
        <p className={styles.notice} id={hintId}>
          {t('accounts.snapshots.confirmHint')}
        </p>
      ) : null}
      {empty ? <p className={styles.notice}>{t('accounts.snapshots.empty')}</p> : null}
      {empty ? null : (
        <BalanceDayList
          headingLevel={4}
          items={items}
          label={t('accounts.snapshots.caption')}
          loadingLabel={t('accounts.snapshots.loading')}
          onEdit={onEdit}
          withdrawal={withdrawal}
        />
      )}
    </div>
  );
}
