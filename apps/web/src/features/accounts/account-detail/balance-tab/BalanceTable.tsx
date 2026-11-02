import type { AccountBalanceSnapshot, DecimalAmount } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { BalanceRowActions } from '@/features/accounts/record-balance-form/balance-snapshots/BalanceRowActions';
import type { BalanceWithdrawal } from '@/features/accounts/record-balance-form/balance-snapshots/useBalanceWithdrawal';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import styles from './BalanceTable.module.css';

const PENDING_ROWS = 6;

interface Props {
  /** `null` while the list loads: static chrome stays real, only the values are blocks. */
  items: readonly AccountBalanceSnapshot[] | null;
  /** Absent while loading, when no row carries an action. */
  withdrawal?: BalanceWithdrawal;
  onEdit?: (snapshot: AccountBalanceSnapshot) => void;
  /** Announced once while the values are pending. */
  loadingStatus?: string;
}

/** Recorded balances, newest first; a withdrawn one stays listed, an uncomputable one reads `-`. */
export function BalanceTable({ items, withdrawal, onEdit, loadingStatus }: Props) {
  const { t, i18n } = useTranslation();
  const columns = ['date', 'amount', 'source'] as const;
  const label = (key: 'date' | 'amount' | 'source' | 'state' | 'active' | 'withdrawn') => {
    switch (key) {
      case 'date':
        return t('accounts.snapshots.date');
      case 'amount':
        return t('accounts.snapshots.amount');
      case 'source':
        return t('accounts.snapshots.source');
      case 'state':
        return t('accounts.snapshots.state');
      case 'active':
        return t('accounts.snapshots.active');
      case 'withdrawn':
        return t('accounts.snapshots.withdrawn');
    }
  };
  const money = (amount: DecimalAmount | null) =>
    amount ? (
      <MoneyValue value={formatAmount(amount.value, amount.assetCode, i18n.language)} />
    ) : (
      <EmptyValue label={t('accounts.balanceTab.nonComputable')} />
    );

  return (
    <div aria-busy={items === null} className={`card ${styles.panel}`}>
      {items === null && loadingStatus ? <SkeletonStatus>{loadingStatus}</SkeletonStatus> : null}
      <div className={styles.scroll}>
        <table>
          <caption className="sr-only">{t('accounts.balanceTab.caption')}</caption>
          <thead>
            <tr>
              {columns.map((column) => (
                <th key={column} scope="col">
                  {label(column)}
                </th>
              ))}
              <th scope="col">{t('accounts.balanceTab.reconciliation')}</th>
              <th scope="col">{label('state')}</th>
              <th scope="col">
                <span className="sr-only">{t('accounts.balanceTab.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody aria-hidden={items === null || undefined}>
            {(items ?? Array.from({ length: PENDING_ROWS }, () => null)).map((snapshot, index) => {
              const amount: DecimalAmount | null = snapshot?.amount ?? null;
              return (
                <tr
                  className={snapshot && amount === null ? styles.greyed : undefined}
                  key={snapshot?.id ?? index}
                >
                  <th data-label={label('date')} scope="row">
                    {snapshot ? (
                      <time dateTime={snapshot.asOf}>
                        {formatCalendarNumericDay(snapshot.asOf)}
                      </time>
                    ) : (
                      <SkeletonBlock />
                    )}
                  </th>
                  <td className={styles.number} data-label={label('amount')}>
                    {snapshot ? money(amount) : <SkeletonBlock />}
                  </td>
                  <td data-label={label('source')}>
                    {snapshot ? (
                      t(`accounts.balances.sources.${snapshot.source}`)
                    ) : (
                      <SkeletonBlock />
                    )}
                  </td>
                  <td data-label={t('accounts.balanceTab.reconciliation')}>
                    {snapshot ? (
                      <StatusBadge
                        tone={
                          snapshot.reconciliationStatus === 'RECONCILED' ? 'positive' : 'neutral'
                        }
                      >
                        {t(
                          snapshot.reconciliationStatus === 'RECONCILED'
                            ? 'accounts.balanceTab.reconciled'
                            : 'accounts.balanceTab.unreconciled',
                        )}
                      </StatusBadge>
                    ) : (
                      <SkeletonBlock />
                    )}
                  </td>
                  <td data-label={label('state')}>
                    {snapshot ? label(snapshot.active ? 'active' : 'withdrawn') : <SkeletonBlock />}
                  </td>
                  <td>
                    {snapshot && withdrawal ? (
                      <BalanceRowActions
                        onEdit={onEdit}
                        snapshot={snapshot}
                        withdrawal={withdrawal}
                      />
                    ) : null}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
