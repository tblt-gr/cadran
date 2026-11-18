import type { AccountBalanceSnapshot } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { DatedRow } from '@/components/ui/dated-list/DatedRow';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { BalanceRowActions } from '@/features/accounts/record-balance-form/balance-snapshots/BalanceRowActions';
import type { BalanceWithdrawal } from '@/features/accounts/record-balance-form/balance-snapshots/useBalanceWithdrawal';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import styles from './BalanceDayRow.module.css';

interface Props {
  /** Null while loading: the row keeps its final structure and shows blocks for the values. */
  snapshot: AccountBalanceSnapshot | null;
  /** Absent while loading, when no row carries an action. */
  withdrawal?: BalanceWithdrawal;
  onEdit?: (snapshot: AccountBalanceSnapshot) => void;
  onOpen?: (snapshot: AccountBalanceSnapshot) => void;
}

/** One recorded balance: its source, its reconciliation and the amount, withdrawn ones greyed. */
export function BalanceDayRow({ snapshot, withdrawal, onEdit, onOpen }: Props) {
  const { i18n, t } = useTranslation();
  const date = snapshot ? formatCalendarNumericDay(snapshot.asOf) : '';
  const amount = snapshot?.amount ?? null;
  const muted = snapshot !== null && (!snapshot.active || amount === null);

  return (
    <DatedRow
      dataAttributes={{ 'data-withdrawn': snapshot ? !snapshot.active : undefined }}
      menu={
        snapshot && withdrawal ? (
          <BalanceRowActions onEdit={onEdit} snapshot={snapshot} withdrawal={withdrawal} />
        ) : undefined
      }
      menuOnPhone
      meta={
        <small>
          {snapshot ? (
            <StatusBadge
              tone={snapshot.reconciliationStatus === 'RECONCILED' ? 'positive' : 'neutral'}
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
        </small>
      }
      onOpen={snapshot && onOpen ? () => onOpen(snapshot) : undefined}
      state={
        muted
          ? {
              tone: 'muted',
              cue: snapshot.active ? undefined : (
                <span className={styles.state}>{t('accounts.snapshots.withdrawn')}</span>
              ),
            }
          : undefined
      }
      title={
        <button
          aria-label={snapshot ? t('accounts.snapshots.detailOf', { date }) : undefined}
          className={styles.source}
          disabled={!snapshot || !onOpen}
          onClick={() => snapshot && onOpen?.(snapshot)}
          type="button"
        >
          {snapshot ? t(`accounts.balances.sources.${snapshot.source}`) : <SkeletonBlock />}
        </button>
      }
      value={
        snapshot ? (
          amount ? (
            <MoneyValue value={formatAmount(amount.value, amount.assetCode, i18n.language)} />
          ) : null
        ) : (
          <SkeletonBlock />
        )
      }
    />
  );
}
