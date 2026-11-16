import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { AccountFilters } from '@/features/accounts/account-filters/AccountFilters';
import { GroupingModeToggle } from '@/features/accounts/grouping-mode/GroupingModeToggle';
import type { GroupingMode } from '@/features/accounts/grouping-mode/groupingMode';
import type { AccountsView } from '@/features/accounts/accounts-view/accountsView';
import { handleClientNavigation } from '@/hooks/use-client-navigation';

interface AccountsPageActionsProps {
  grouping: GroupingMode;
  onGroupingChange: (mode: GroupingMode) => void;
  includeArchived: boolean;
  includeClosed: boolean;
  onCreate: () => void;
  onIncludeArchivedChange: (checked: boolean) => void;
  onIncludeClosedChange: (checked: boolean) => void;
  onToggleReordering: () => void;
  reordering: boolean;
  view: AccountsView;
}

/** One primary action and the tools that organise the account overview. */
export function AccountsPageActions({
  grouping,
  onGroupingChange,
  includeArchived,
  includeClosed,
  onCreate,
  onIncludeArchivedChange,
  onIncludeClosedChange,
  onToggleReordering,
  reordering,
  view,
}: AccountsPageActionsProps) {
  const { t } = useTranslation();
  const reorderLabel = t(reordering ? 'accounts.reorder.stop' : 'accounts.reorder.start');

  return (
    <>
      <HeaderActions>
        <button className="primary-action" onClick={onCreate} type="button">
          <Icon name="add" size={18} />
          {t('accounts.add')}
        </button>
        <a
          className="secondary-action"
          href="/accounts/groups"
          onClick={(event) => handleClientNavigation(event, '/accounts/groups')}
        >
          <Icon name="folder" size={18} />
          {t('accounts.manageGroups')}
        </a>
        {view === 'grouped' ? (
          <GroupingModeToggle className="icon-button" mode={grouping} onChange={onGroupingChange} />
        ) : null}
        {view === 'grouped' ? (
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
        ) : null}
      </HeaderActions>
      <AccountFilters
        includeArchived={includeArchived}
        includeClosed={includeClosed}
        onIncludeArchivedChange={onIncludeArchivedChange}
        onIncludeClosedChange={onIncludeClosedChange}
      />
    </>
  );
}
