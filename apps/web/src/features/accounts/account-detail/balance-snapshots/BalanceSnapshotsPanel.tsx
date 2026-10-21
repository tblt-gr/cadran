import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { AccountBalanceSnapshot } from '@cadran/api-client';
import { Modal } from '@/components/ui/modal/Modal';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { accountErrorKind } from '@/features/accounts/accountError';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import { useBalanceSnapshots, useWithdrawBalance } from './useBalanceSnapshots';
import { WithdrawBalanceDialog } from './WithdrawBalanceDialog';
import styles from './BalanceSnapshotsPanel.module.css';

export function BalanceSnapshotsPanel({ accountId }: { accountId: string }) {
  const { t, i18n } = useTranslation();
  const id = useId();
  const snapshots = useBalanceSnapshots(accountId);
  const withdraw = useWithdrawBalance(accountId);
  const [target, setTarget] = useState<AccountBalanceSnapshot | null>(null);
  const pending = snapshots.isPending;
  const items = snapshots.data?.items ?? null;

  function close() {
    withdraw.reset();
    setTarget(null);
  }

  return (
    <section aria-busy={pending} aria-labelledby={id} className={`card ${styles.card}`}>
      {pending ? <SkeletonStatus>{t('accounts.snapshots.loading')}</SkeletonStatus> : null}
      <h3 id={id}>{t('accounts.snapshots.title')}</h3>
      <p className={styles.notice}>{t('accounts.snapshots.description')}</p>
      {snapshots.isError ? (
        <div role="alert">
          <p>{t('accounts.snapshots.error')}</p>
          <button
            className="secondary-action"
            onClick={() => void snapshots.refetch()}
            type="button"
          >
            {t('foundation.retry')}
          </button>
        </div>
      ) : null}
      {pending || (items !== null && items.length > 0) ? (
        <div
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
                      <time dateTime={snapshot.asOf}>
                        {formatCalendarNumericDay(snapshot.asOf)}
                      </time>
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
                    {snapshot ? (
                      t(`accounts.balances.sources.${snapshot.source}`)
                    ) : (
                      <SkeletonBlock />
                    )}
                  </td>
                  <td data-label={t('accounts.snapshots.state')}>
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
                    <button
                      aria-label={
                        snapshot
                          ? t('accounts.snapshots.withdrawOf', {
                              date: formatCalendarNumericDay(snapshot.asOf),
                            })
                          : t('accounts.snapshots.withdraw')
                      }
                      className="secondary-action"
                      disabled={
                        pending || snapshot === null || !snapshot.active || withdraw.isPending
                      }
                      onClick={() => snapshot && setTarget(snapshot)}
                      type="button"
                    >
                      {t('accounts.snapshots.withdraw')}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
      {!pending && items && items.length === 0 ? (
        <p role="status">{t('accounts.snapshots.empty')}</p>
      ) : null}
      {target ? (
        <Modal
          close={close}
          eyebrow={t('accounts.snapshots.eyebrow')}
          title={t('accounts.snapshots.confirmTitle', {
            date: formatCalendarNumericDay(target.asOf),
          })}
        >
          <WithdrawBalanceDialog
            onConfirm={() =>
              withdraw.mutate(target, {
                onSuccess: close,
              })
            }
            pending={withdraw.isPending}
            snapshot={target}
            submitError={accountErrorKind(withdraw.error, withdraw.isError)}
          />
        </Modal>
      ) : null}
    </section>
  );
}
