import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { AccountFilters } from '@/features/accounts/account-filters/AccountFilters';

interface AccountsPageActionsProps {
  includeArchived: boolean;
  includeClosed: boolean;
  onCreate: () => void;
  onIncludeArchivedChange: (checked: boolean) => void;
  onIncludeClosedChange: (checked: boolean) => void;
}

/** The primary action and the filters of the account overview. */
export function AccountsPageActions({
  includeArchived,
  includeClosed,
  onCreate,
  onIncludeArchivedChange,
  onIncludeClosedChange,
}: AccountsPageActionsProps) {
  const { t } = useTranslation();

  return (
    <>
      <HeaderActions>
        <button className="primary-action" onClick={onCreate} type="button">
          <Icon name="add" size={18} />
          {t('accounts.add')}
        </button>
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
