import type { Account, AccountOverviewGroup } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { AccountList } from '@/features/accounts/account-list/AccountList';
import type { GroupingMode } from '@/features/accounts/grouping-mode/groupingMode';
import { useAllRows } from './useAllRows';

interface AllAccountsTableProps {
  asOf: string;
  groups: readonly AccountOverviewGroup[];
  grouping: GroupingMode;
  includeArchived: boolean;
  includeClosed: boolean;
  onArchive: (account: Account) => void;
  onEdit: (account: Account) => void;
  onReconcile: (account: Account) => void;
  onRecordBalance: (account: Account) => void;
  onRules: (account: Account) => void;
}

const noMove = () => undefined;

/** Every account in one table, without groups: no group order to move inside, no group total. */
export function AllAccountsTable({
  asOf,
  groups,
  grouping,
  includeArchived,
  includeClosed,
  ...rowActions
}: AllAccountsTableProps) {
  const { t } = useTranslation();
  const rows = useAllRows({ asOf, groups, grouping, includeArchived, includeClosed });

  return (
    <AccountList
      accounts={rows.data ?? null}
      label={t('accounts.view.allCaption')}
      moveDisabled
      onMove={noMove}
      {...rowActions}
    />
  );
}
