import type {
  Account,
  AccountGroup,
  NetWorthAllocationEntry,
  NetWorthContribution,
  NetWorthReason,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { SortableHeader } from '@/components/ui/sortable-header/SortableHeader';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { GroupMembers } from '@/features/account-groups/group-members/GroupMembers';
import { ShareCell } from '@/features/account-groups/share-cell/ShareCell';
import { useProductOptions } from '@/features/accounts/account-wizard/useProductOptions';
import { useTemplateOptions } from '@/features/accounts/account-wizard/useTemplateOptions';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { compareDecimalString, compareText, toggleSort, type SortDirection } from '@/lib/tableSort';
import styles from './GroupList.module.css';

type LoadStatus = 'error' | 'pending' | 'ready';
type GroupSortColumn = 'label' | 'parent' | 'share' | 'status' | 'total';

interface GroupListProps {
  accounts: Account[];
  accountsStatus: LoadStatus;
  allocation: NetWorthAllocationEntry[];
  allocationStatus: LoadStatus;
  contributions: NetWorthContribution[];
  groups: AccountGroup[];
  netWorthReason: NetWorthReason | null;
  onArchive: (group: AccountGroup) => void;
  onEdit: (group: AccountGroup) => void;
}

function totalSortKey(entry: NetWorthAllocationEntry | undefined): string | null {
  return entry?.value?.display?.value ?? entry?.value?.value ?? null;
}

export function GroupList({
  accounts,
  accountsStatus,
  allocation,
  allocationStatus,
  contributions,
  groups,
  netWorthReason,
  onArchive,
  onEdit,
}: GroupListProps) {
  const { i18n, t } = useTranslation();
  const [sort, setSort] = useState<{ column: GroupSortColumn; direction: SortDirection }>({
    column: 'label',
    direction: 'asc',
  });
  const asOf = accounts.find((account) => account.valuation.requestedOn)?.valuation.requestedOn;
  const products = useProductOptions(asOf ?? '');
  const models = useTemplateOptions();
  const allocationByGroup = new Map(allocation.map((entry) => [entry.groupId, entry]));
  const shareByAccount = new Map(
    contributions.map((contribution) => [contribution.accountId, contribution.share]),
  );
  const accountsByGroup = new Map<string, Account[]>();
  for (const account of accounts) {
    if (account.primaryGroupId === null) {
      continue;
    }

    const members = accountsByGroup.get(account.primaryGroupId) ?? [];
    members.push(account);
    accountsByGroup.set(account.primaryGroupId, members);
  }

  function parentLabel(group: AccountGroup): string {
    return group.parentId
      ? (group.parentLabel ?? t('accountGroups.list.unavailableParent'))
      : t('accountGroups.form.noParent');
  }

  function statusLabel(group: AccountGroup): string {
    return t(group.archivedAt ? 'accountGroups.list.archived' : 'accountGroups.list.active');
  }

  function onSort(column: string) {
    const next = toggleSort(sort.column, sort.direction, column);
    setSort({ column: next.column as GroupSortColumn, direction: next.direction });
  }

  const rows = [...groups].sort((left, right) => {
    const leftEntry = allocationByGroup.get(left.id);
    const rightEntry = allocationByGroup.get(right.id);

    switch (sort.column) {
      case 'label':
        return compareText(left.label, right.label, i18n.language, sort.direction);
      case 'parent':
        return compareText(parentLabel(left), parentLabel(right), i18n.language, sort.direction);
      case 'total':
        return compareDecimalString(
          totalSortKey(leftEntry),
          totalSortKey(rightEntry),
          sort.direction,
        );
      case 'share':
        return compareDecimalString(
          leftEntry?.share.percent ?? left.share.percent,
          rightEntry?.share.percent ?? right.share.percent,
          sort.direction,
        );
      case 'status':
        return compareText(statusLabel(left), statusLabel(right), i18n.language, sort.direction);
    }
  });

  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('accountGroups.list.caption')}</caption>
          <thead>
            <tr>
              <SortableHeader
                column="label"
                current={sort.column}
                direction={sort.direction}
                onSort={onSort}
              >
                {t('accountGroups.fields.label')}
              </SortableHeader>
              <SortableHeader
                column="parent"
                current={sort.column}
                direction={sort.direction}
                onSort={onSort}
              >
                {t('accountGroups.fields.parent')}
              </SortableHeader>
              <SortableHeader
                column="total"
                current={sort.column}
                direction={sort.direction}
                onSort={onSort}
              >
                {t('accountGroups.fields.total')}
              </SortableHeader>
              <SortableHeader
                column="share"
                current={sort.column}
                direction={sort.direction}
                onSort={onSort}
              >
                {t('accountGroups.fields.share')}
              </SortableHeader>
              <SortableHeader
                column="status"
                current={sort.column}
                direction={sort.direction}
                onSort={onSort}
              >
                {t('accountGroups.list.status')}
              </SortableHeader>
              <th scope="col">
                <span className="sr-only">{t('accountGroups.list.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((group) => {
              const entry = allocationByGroup.get(group.id);
              const members = [...(accountsByGroup.get(group.id) ?? [])].sort((left, right) =>
                left.label.localeCompare(right.label, i18n.language),
              );

              return (
                <tr key={group.id}>
                  <th scope="row">
                    <span>{group.label}</span>
                    <small>{t('accountGroups.list.depth', { depth: group.depth })}</small>
                    <GroupMembers
                      accounts={members}
                      models={models.items}
                      products={products.items}
                      shareByAccount={shareByAccount}
                      sharesStatus={allocationStatus}
                      status={accountsStatus}
                    />
                  </th>
                  <td>{parentLabel(group)}</td>
                  <td className={`money ${styles.total}`}>
                    {allocationStatus === 'pending' ? (
                      <span className={styles.pending}>{t('accountGroups.list.totalLoading')}</span>
                    ) : (
                      <NetWorthFigure amount={entry?.value ?? null} reason={netWorthReason} />
                    )}
                  </td>
                  <td>
                    {allocationStatus === 'pending' ? (
                      <span className={styles.pending}>{t('accountGroups.list.shareLoading')}</span>
                    ) : (
                      <ShareCell share={entry?.share ?? group.share} />
                    )}
                  </td>
                  <td>
                    <StatusBadge tone={group.archivedAt ? 'warning' : 'positive'}>
                      {statusLabel(group)}
                    </StatusBadge>
                  </td>
                  <td>
                    <div className={styles.rowActions}>
                      <ActionMenu
                        label={t('accountGroups.list.openActions', { label: group.label })}
                        items={[
                          {
                            disabled: group.archivedAt !== null,
                            icon: 'edit',
                            id: 'edit',
                            label: t('accountGroups.list.editGroup', { label: group.label }),
                            onSelect: () => onEdit(group),
                            text: t('accountGroups.list.edit'),
                          },
                          {
                            disabled: group.archivedAt !== null || group.hasChildren,
                            icon: 'archive',
                            id: 'archive',
                            label: t('accountGroups.list.archiveGroup', { label: group.label }),
                            onSelect: () => onArchive(group),
                            text: t('accountGroups.list.archive'),
                          },
                        ]}
                      />
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
