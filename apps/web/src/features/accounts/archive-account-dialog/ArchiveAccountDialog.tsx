import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { AccountErrorKind } from '@/features/accounts/accountError';
import styles from './ArchiveAccountDialog.module.css';

interface ArchiveAccountDialogProps {
  account: Account;
  pending: boolean;
  submitError: AccountErrorKind | null;
  onCancel: () => void;
  onConfirm: () => void;
}

/**
 * Archiving is not a deletion, and the wording says so: the account keeps its
 * history and can still be read; it only leaves the working set and frees its
 * label.
 */
export function ArchiveAccountDialog({
  account,
  pending,
  submitError,
  onCancel: _onCancel,
  onConfirm,
}: ArchiveAccountDialogProps) {
  const { t } = useTranslation();

  return (
    <div className={styles.dialog}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`accounts.errors.${submitError}`)}
        </p>
      ) : null}
      <p>{t('accounts.archive.description', { label: account.label })}</p>
      <p className={styles.hint}>{t('accounts.archive.consequences')}</p>
      <div className={styles.actions}>
        <button
          className="primary-action"
          data-autofocus
          disabled={pending}
          onClick={onConfirm}
          type="button"
        >
          {t(pending ? 'accounts.archive.confirming' : 'accounts.archive.confirm')}
        </button>
      </div>
    </div>
  );
}
