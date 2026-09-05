import type {
  Account,
  AccountGroup,
  NetWorthAllocationEntry,
  NetWorthReason,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { GroupMembers } from '@/features/account-groups/group-members/GroupMembers';
import { ShareCell } from '@/features/account-groups/share-cell/ShareCell';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import styles from './GroupList.module.css';

type LoadStatus = 'error' | 'pending' | 'ready';

interface GroupListProps {
  accounts: Account[];
  accountsStatus: LoadStatus;
  allocation: NetWorthAllocationEntry[];
  allocationStatus: LoadStatus;
  groups: AccountGroup[];
  netWorthReason: NetWorthReason | null;
  onArchive: (group: AccountGroup) => void;
  onEdit: (group: AccountGroup) => void;
}

export function GroupList({
  accounts,
  accountsStatus,
  allocation,
  allocationStatus,
  groups,
  netWorthReason,
  onArchive,
  onEdit,
}: GroupListProps) {
  const { i18n, t } = useTranslation();
  const allocationByGroup = new Map(allocation.map((entry) => [entry.groupId, entry]));
  const accountsByGroup = new Map<string, Account[]>();
  for (const account of accounts) {
    if (account.primaryGroupId === null) {
      continue;
    }

    const members = accountsByGroup.get(account.primaryGroupId) ?? [];
    members.push(account);
    accountsByGroup.set(account.primaryGroupId, members);
  }

  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('accountGroups.list.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('accountGroups.fields.label')}</th>
              <th scope="col">{t('accountGroups.fields.parent')}</th>
              <th scope="col">{t('accountGroups.fields.total')}</th>
              <th scope="col">{t('accountGroups.fields.share')}</th>
              <th scope="col">{t('accountGroups.list.status')}</th>
              <th scope="col">
                <span className="sr-only">{t('accountGroups.list.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {groups.map((group) => {
              const entry = allocationByGroup.get(group.id);
              const members = [...(accountsByGroup.get(group.id) ?? [])].sort((left, right) =>
                left.label.localeCompare(right.label, i18n.language),
              );

              return (
                <tr key={group.id}>
                  <th scope="row">
                    <span>{group.label}</span>
                    <small>{t('accountGroups.list.depth', { depth: group.depth })}</small>
                    <GroupMembers accounts={members} status={accountsStatus} />
                  </th>
                  <td>
                    {group.parentId
                      ? (group.parentLabel ?? t('accountGroups.list.unavailableParent'))
                      : t('accountGroups.form.noParent')}
                  </td>
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
                      {t(
                        group.archivedAt
                          ? 'accountGroups.list.archived'
                          : 'accountGroups.list.active',
                      )}
                    </StatusBadge>
                  </td>
                  <td>
                    <div className={styles.rowActions}>
                      <button
                        aria-label={t('accountGroups.list.editGroup', { label: group.label })}
                        className="secondary-action"
                        disabled={group.archivedAt !== null}
                        onClick={() => onEdit(group)}
                        type="button"
                      >
                        {t('accountGroups.list.edit')}
                      </button>
                      <button
                        aria-label={t('accountGroups.list.archiveGroup', { label: group.label })}
                        className="secondary-action"
                        disabled={group.archivedAt !== null || group.hasChildren}
                        onClick={() => onArchive(group)}
                        type="button"
                      >
                        {t('accountGroups.list.archive')}
                      </button>
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
