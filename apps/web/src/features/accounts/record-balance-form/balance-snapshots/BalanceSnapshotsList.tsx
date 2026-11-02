import type { AccountBalanceSnapshot } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import { BalanceRowActions } from './BalanceRowActions';
import { useBalanceSnapshots } from './useBalanceSnapshots';
import { useBalanceWithdrawal } from './useBalanceWithdrawal';
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
  const { t, i18n } = useTranslation();
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
      {pending ? <SkeletonStatus>{t('accounts.snapshots.loading')}</SkeletonStatus> : null}
      <p className={styles.notice}>{t('accounts.snapshots.description')}</p>
      {total > (items?.length ?? 0) ? (
        <p className={styles.notice}>
          {t('accounts.snapshots.truncated', { shown: items?.length ?? 0, total })}
        </p>
      ) : null}
      {withdrawal.error ? (
        <p className={styles.alert} role="alert">
          {t(`accounts.balances.errors.${withdrawal.error}`)}
        </p>
      ) : null}
      {withdrawal.confirmingId !== null ? (
        <p className={styles.notice} id={hintId}>
          {t('accounts.snapshots.confirmHint')}
        </p>
      ) : null}
      {empty ? <p className={styles.notice}>{t('accounts.snapshots.empty')}</p> : null}
      <div
        hidden={empty}
        aria-label={t('accounts.snapshots.caption')}
        className={styles.scroll}
        role="region"
        tabIndex={0}
      >
        <table>
          <caption className="sr-only">{t('accounts.snapshots.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('accounts.snapshots.date')}</th>
              <th scope="col">{t('accounts.snapshots.amount')}</th>
              <th scope="col">{t('accounts.snapshots.source')}</th>
              <th scope="col">{t('accounts.snapshots.state')}</th>
              <th scope="col">
                <span className="sr-only">{t('accounts.snapshots.withdraw')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {(items ?? [null, null, null]).map((snapshot, index) => (
              <tr key={snapshot?.id ?? index}>
                <th data-label={t('accounts.snapshots.date')} scope="row">
                  {snapshot ? (
                    <time dateTime={snapshot.asOf}>{formatCalendarNumericDay(snapshot.asOf)}</time>
                  ) : (
                    <SkeletonBlock />
                  )}
                </th>
                <td data-label={t('accounts.snapshots.amount')}>
                  {snapshot ? (
                    formatAmount(snapshot.amount.value, snapshot.amount.assetCode, i18n.language)
                  ) : (
                    <SkeletonBlock />
                  )}
                </td>
                <td data-label={t('accounts.snapshots.source')}>
                  {snapshot ? t(`accounts.balances.sources.${snapshot.source}`) : <SkeletonBlock />}
                </td>
                <td className={styles.state} data-label={t('accounts.snapshots.state')}>
                  {snapshot ? (
                    t(
                      snapshot.active
                        ? 'accounts.snapshots.active'
                        : 'accounts.snapshots.withdrawn',
                    )
                  ) : (
                    <SkeletonBlock />
                  )}
                </td>
                <td>
                  {snapshot ? (
                    <BalanceRowActions
                      onEdit={onEdit}
                      snapshot={snapshot}
                      withdrawal={withdrawal}
                    />
                  ) : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
