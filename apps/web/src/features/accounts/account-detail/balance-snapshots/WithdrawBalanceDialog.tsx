import type { AccountBalanceSnapshot } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { AccountErrorKind } from '@/features/accounts/accountError';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './BalanceSnapshotsPanel.module.css';

export function WithdrawBalanceDialog({
  snapshot,
  pending,
  submitError,
  onConfirm,
}: {
  snapshot: AccountBalanceSnapshot;
  pending: boolean;
  submitError: AccountErrorKind | null;
  onConfirm: () => void;
}) {
  const { t, i18n } = useTranslation();
  const date = formatCalendarDay(snapshot.asOf, i18n.language);

  return (
    <div className={styles.dialog}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`accounts.balances.errors.${submitError}`)}
        </p>
      ) : null}
      <p>{t('accounts.snapshots.confirm', { date })}</p>
      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} onClick={onConfirm} type="button">
          {t(pending ? 'accounts.snapshots.confirming' : 'accounts.snapshots.confirmAction')}
        </button>
      </div>
    </div>
  );
}
