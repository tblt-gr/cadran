import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { accountErrorKind } from '@/features/accounts/accountError';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import { useBalanceSnapshots, useWithdrawBalance } from './useBalanceSnapshots';
import styles from './BalanceSnapshotsList.module.css';

/**
 * Every snapshot recorded on the account, newest first. Only an active one can
 * be withdrawn; a withdrawn one stays listed as such. The withdrawal is
 * confirmed in the row itself, since the list lives inside a modal.
 */
export function BalanceSnapshotsList({ accountId }: { accountId: string }) {
  const { t, i18n } = useTranslation();
  const snapshots = useBalanceSnapshots(accountId);
  const withdraw = useWithdrawBalance(accountId);
  const [confirmingId, setConfirming] = useState<string | null>(null);
  const withdrawError = accountErrorKind(withdraw.error, withdraw.isError);
  const pending = snapshots.isPending;
  const items = snapshots.data?.items ?? null;
  const total = snapshots.data?.total ?? 0;
  // A row withdrawn elsewhere no longer carries a confirmation.
  const confirming = items?.find((item) => item.id === confirmingId && item.active)?.id ?? null;
  const confirmRef = useRef<HTMLButtonElement>(null);
  const hintId = useId();
  const empty = !pending && items !== null && items.length === 0;

  // The button that opened the confirmation is replaced, so focus moves to the new one.
  useEffect(() => {
    if (confirming !== null) {
      confirmRef.current?.focus();
    }
  }, [confirming]);

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
      {withdrawError ? (
        <p className={styles.alert} role="alert">
          {t(`accounts.balances.errors.${withdrawError}`)}
        </p>
      ) : null}
      {confirming !== null ? (
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
                  {snapshot?.active && snapshot.source !== 'CALCULATED' ? (
                    confirming === snapshot.id ? (
                      <div className={styles.actions}>
                        <button
                          aria-describedby={hintId}
                          className="primary-action"
                          ref={confirmRef}
                          disabled={withdraw.isPending}
                          onClick={() =>
                            withdraw.mutate(snapshot, { onSuccess: () => setConfirming(null) })
                          }
                          type="button"
                        >
                          {t(
                            withdraw.isPending
                              ? 'accounts.snapshots.confirming'
                              : 'accounts.snapshots.confirmAction',
                          )}
                        </button>
                        <button
                          className="secondary-action"
                          disabled={withdraw.isPending}
                          onClick={() => {
                            withdraw.reset();
                            setConfirming(null);
                          }}
                          type="button"
                        >
                          {t('actions.cancel')}
                        </button>
                      </div>
                    ) : (
                      <div className={styles.actions}>
                        <button
                          aria-label={t('accounts.snapshots.withdrawOf', {
                            date: formatCalendarNumericDay(snapshot.asOf),
                          })}
                          className="secondary-action"
                          onClick={() => {
                            withdraw.reset();
                            setConfirming(snapshot.id);
                          }}
                          type="button"
                        >
                          {t('accounts.snapshots.withdraw')}
                        </button>
                      </div>
                    )
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
