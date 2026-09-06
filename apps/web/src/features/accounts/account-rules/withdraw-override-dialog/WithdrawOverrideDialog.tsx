import { useTranslation } from 'react-i18next';
import type { AccountErrorKind } from '@/features/accounts/accountError';
import styles from './WithdrawOverrideDialog.module.css';

interface WithdrawOverrideDialogProps {
  onCancel: () => void;
  onConfirm: () => void;
  pending: boolean;
  reason: string;
  submitError: AccountErrorKind | null;
}

/**
 * Withdrawing is not ending, and the wording says so: ending a claim is a date
 * recorded in its period, and the account keeps resolving against it up to
 * that day for ever after. Withdrawing removes it from every date, past ones
 * included, and the inherited rule of each of those dates takes over again.
 *
 * The claim itself is kept and stays readable, which is why this is not
 * offered as a deletion.
 */
export function WithdrawOverrideDialog({
  onCancel: _onCancel,
  onConfirm,
  pending,
  reason,
  submitError,
}: WithdrawOverrideDialogProps) {
  const { t } = useTranslation();

  return (
    <div className={styles.dialog}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`accounts.overrides.errors.${submitError}`)}
        </p>
      ) : null}
      <p>{t('accounts.overrides.withdrawDescription')}</p>
      <p className={styles.quote}>{reason}</p>
      <p className={styles.hint}>{t('accounts.overrides.withdrawConsequences')}</p>
      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} onClick={onConfirm} type="button">
          {t(pending ? 'accounts.overrides.withdrawing' : 'accounts.overrides.withdrawConfirm')}
        </button>
      </div>
    </div>
  );
}
