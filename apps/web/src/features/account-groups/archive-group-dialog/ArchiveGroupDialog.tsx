import type { AccountGroup } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { GroupErrorKind } from '@/features/account-groups/groupError';
import styles from './ArchiveGroupDialog.module.css';

interface ArchiveGroupDialogProps {
  group: AccountGroup;
  pending: boolean;
  submitError: GroupErrorKind | null;
  onCancel: () => void;
  onConfirm: () => void;
}

export function ArchiveGroupDialog({
  group,
  pending,
  submitError,
  onCancel: _onCancel,
  onConfirm,
}: ArchiveGroupDialogProps) {
  const { t } = useTranslation();

  return (
    <div className={styles.dialog}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`accountGroups.errors.${submitError}`)}
        </p>
      ) : null}
      <p>{t('accountGroups.archive.description', { label: group.label })}</p>
      <p className={styles.hint}>{t('accountGroups.archive.consequences')}</p>
      <div className={styles.actions}>
        <button
          className="primary-action"
          data-autofocus
          disabled={pending}
          onClick={onConfirm}
          type="button"
        >
          {t(pending ? 'accountGroups.archive.confirming' : 'accountGroups.archive.confirm')}
        </button>
      </div>
    </div>
  );
}
