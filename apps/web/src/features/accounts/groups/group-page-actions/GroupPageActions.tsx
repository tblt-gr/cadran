import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './GroupPageActions.module.css';

interface GroupPageActionsProps {
  includeArchived: boolean;
  reordering: boolean;
  onAdd: () => void;
  onToggleArchived: () => void;
  onToggleReordering: () => void;
}

/** The page-level actions the header hosts: add a group, show archived ones, reorder. */
export function GroupPageActions({
  includeArchived,
  reordering,
  onAdd,
  onToggleArchived,
  onToggleReordering,
}: GroupPageActionsProps) {
  const { t } = useTranslation();
  const reorderLabel = t(reordering ? 'accountGroups.reorder.stop' : 'accountGroups.reorder.start');

  return (
    <HeaderActions>
      <button
        aria-label={t('accountGroups.add')}
        className={`primary-action ${styles.add}`}
        onClick={onAdd}
        type="button"
      >
        <Icon name="add" size={18} />
        <span className={styles.addLabel}>{t('accountGroups.add')}</span>
      </button>
      <button
        aria-label={t('accountGroups.includeArchived')}
        aria-pressed={includeArchived}
        className="icon-button"
        onClick={onToggleArchived}
        title={t('accountGroups.includeArchived')}
        type="button"
      >
        <Icon name="archive" />
      </button>
      <button
        aria-label={reorderLabel}
        aria-pressed={reordering}
        className={`secondary-action ${styles.secondary}`}
        onClick={onToggleReordering}
        title={reorderLabel}
        type="button"
      >
        <Icon name={reordering ? 'check-circle' : 'reorder'} />
        <span className={styles.secondaryLabel}>
          {t(reordering ? 'accountGroups.reorder.stop_short' : 'accountGroups.reorder.start_short')}
        </span>
      </button>
    </HeaderActions>
  );
}
