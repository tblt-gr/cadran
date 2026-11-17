import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { AccountsViewSwitch } from '@/features/accounts/accounts-view/AccountsViewSwitch';
import type { AccountsView } from '@/features/accounts/accounts-view/accountsView';
import { GroupingModeToggle } from '@/features/accounts/grouping-mode/GroupingModeToggle';
import type { GroupingMode } from '@/features/accounts/grouping-mode/groupingMode';
import styles from './AccountsViewBar.module.css';

interface AccountsViewBarProps {
  grouping: GroupingMode;
  onGroupingChange: (mode: GroupingMode) => void;
  onToggleReordering: () => void;
  onViewChange: (view: AccountsView) => void;
  reordering: boolean;
  view: AccountsView;
}

/** One line: the view switch on the left, the tools of the grouped view (sub-groups, order) on the right. */
export function AccountsViewBar({
  grouping,
  onGroupingChange,
  onToggleReordering,
  onViewChange,
  reordering,
  view,
}: AccountsViewBarProps) {
  const { t } = useTranslation();
  const reorderLabel = t(reordering ? 'accounts.reorder.stop' : 'accounts.reorder.start');

  return (
    <div className={styles.bar}>
      <AccountsViewSwitch onChange={onViewChange} value={view} />
      {view === 'grouped' ? (
        <div className={styles.tools}>
          <GroupingModeToggle className="icon-button" mode={grouping} onChange={onGroupingChange} />
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
        </div>
      ) : null}
    </div>
  );
}
