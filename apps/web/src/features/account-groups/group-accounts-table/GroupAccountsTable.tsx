import type { Account, AccountGroup } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SortableHeader } from '@/components/ui/sortable-header/SortableHeader';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { ShareCell } from '@/features/account-groups/share-cell/ShareCell';
import { AccountValuationCell } from '@/features/accounts/account-valuation-cell/AccountValuationCell';
import { compareDecimalString, compareText, toggleSort, type SortDirection } from '@/lib/tableSort';
import styles from './GroupAccountsTable.module.css';

type AccountSortColumn = 'group' | 'kind' | 'label' | 'share' | 'status' | 'valuation';

const STATUS_TONE = {
  ACTIVE: 'positive',
  CLOSED: 'info',
  ARCHIVED: 'warning',
} as const;

interface GroupAccountsTableProps {
  accounts: Account[];
  groups: AccountGroup[];
}

function valuationSortKey(account: Account): string | null {
  return account.valuation.display?.value ?? account.valuation.amount?.value ?? null;
}

export function GroupAccountsTable({ accounts, groups }: GroupAccountsTableProps) {
  const { i18n, t } = useTranslation();
  const [sort, setSort] = useState<{ column: AccountSortColumn; direction: SortDirection }>({
    column: 'label',
    direction: 'asc',
  });
  const groupLabelById = new Map(groups.map((group) => [group.id, group.label]));

  function onSort(column: string) {
    const next = toggleSort(sort.column, sort.direction, column);
    setSort({ column: next.column as AccountSortColumn, direction: next.direction });
  }

  function groupLabel(account: Account): string {
    return account.primaryGroupId === null
      ? t('accounts.form.noGroup')
      : (groupLabelById.get(account.primaryGroupId) ?? t('accounts.form.noGroup'));
  }

  function kindLabel(account: Account): string {
    return t(`accounts.kinds.${account.kind}`);
  }

  function statusLabel(account: Account): string {
    return t(`accounts.statuses.${account.status}`);
  }

  const rows = [...accounts].sort((left, right) => {
    switch (sort.column) {
      case 'label':
        return compareText(left.label, right.label, i18n.language, sort.direction);
      case 'group':
        return compareText(groupLabel(left), groupLabel(right), i18n.language, sort.direction);
      case 'kind':
        return compareText(kindLabel(left), kindLabel(right), i18n.language, sort.direction);
      case 'valuation':
        return compareDecimalString(
          valuationSortKey(left),
          valuationSortKey(right),
          sort.direction,
        );
      case 'share':
        return compareDecimalString(left.share.percent, right.share.percent, sort.direction);
      case 'status':
        return compareText(statusLabel(left), statusLabel(right), i18n.language, sort.direction);
    }
  });

  if (rows.length === 0) {
    return null;
  }

  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('accountGroups.list.accountsCaption')}</caption>
          <thead>
            <tr>
              <SortableHeader
                column="label"
                current={sort.column}
                direction={sort.direction}
                onSort={onSort}
              >
                {t('accounts.fields.label')}
              </SortableHeader>
              <SortableHeader
                column="group"
                current={sort.column}
                direction={sort.direction}
                onSort={onSort}
              >
                {t('accountGroups.list.accountGroup')}
              </SortableHeader>
              <SortableHeader
                column="kind"
                current={sort.column}
                direction={sort.direction}
                onSort={onSort}
              >
                {t('accounts.fields.kind')}
              </SortableHeader>
              <SortableHeader
                column="valuation"
                current={sort.column}
                direction={sort.direction}
                onSort={onSort}
              >
                {t('accounts.fields.balance')}
              </SortableHeader>
              <SortableHeader
                column="share"
                current={sort.column}
                direction={sort.direction}
                onSort={onSort}
              >
                {t('accounts.fields.share')}
              </SortableHeader>
              <SortableHeader
                column="status"
                current={sort.column}
                direction={sort.direction}
                onSort={onSort}
              >
                {t('accounts.list.status')}
              </SortableHeader>
            </tr>
          </thead>
          <tbody>
            {rows.map((account) => (
              <tr key={account.id}>
                <th scope="row">{account.label}</th>
                <td>{groupLabel(account)}</td>
                <td>{kindLabel(account)}</td>
                <td>
                  <AccountValuationCell valuation={account.valuation} />
                </td>
                <td>
                  <ShareCell share={account.share} />
                </td>
                <td>
                  <StatusBadge tone={STATUS_TONE[account.status]}>
                    {account.status === 'CLOSED' && account.closedOn
                      ? `${t('accounts.statuses.CLOSED')} · ${account.closedOn}`
                      : t(`accounts.statuses.${account.status}`)}
                  </StatusBadge>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
