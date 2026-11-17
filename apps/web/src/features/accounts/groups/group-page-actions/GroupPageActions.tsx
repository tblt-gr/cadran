import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';

interface GroupPageActionsProps {
  includeArchived: boolean;
  reordering: boolean;
  onAdd: () => void;
  onToggleArchived: () => void;
  onToggleReordering: () => void;
}

/** The page-level actions the header hosts: add a group, reorder, show archived ones. */
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
      <button className="primary-action" onClick={onAdd} type="button">
        <Icon name="add" size={18} />
        {t('accountGroups.add')}
      </button>
      <button
        aria-label={reorderLabel}
        aria-pressed={reordering}
        className="icon-button"
        onClick={onToggleReordering}
        title={reorderLabel}
        type="button"
      >
        <Icon name={reordering ? 'check-circle' : 'reorder'} />
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
    </HeaderActions>
  );
}
