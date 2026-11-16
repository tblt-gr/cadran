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
      <button className="primary-action" onClick={onAdd} type="button">
        <Icon name="add" size={18} />
        {t('accountGroups.add')}
      </button>
      <button
        aria-pressed={includeArchived}
        className="secondary-action"
        onClick={onToggleArchived}
        type="button"
      >
        <Icon name="archive" size={18} />
        {t('accountGroups.includeArchived')}
      </button>
      <button
        aria-pressed={reordering}
        className="secondary-action"
        onClick={onToggleReordering}
        type="button"
      >
        <Icon name={reordering ? 'check-circle' : 'reorder'} size={18} />
        {reorderLabel}
      </button>
    </HeaderActions>
  );
}
