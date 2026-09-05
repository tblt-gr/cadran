import type { AccountGroup } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { ShareCell } from '@/features/account-groups/share-cell/ShareCell';
import styles from './GroupList.module.css';

interface GroupListProps {
  groups: AccountGroup[];
  onArchive: (group: AccountGroup) => void;
  onEdit: (group: AccountGroup) => void;
}

export function GroupList({ groups, onArchive, onEdit }: GroupListProps) {
  const { t } = useTranslation();

  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('accountGroups.list.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('accountGroups.fields.label')}</th>
              <th scope="col">{t('accountGroups.fields.parent')}</th>
              <th scope="col">{t('accountGroups.fields.share')}</th>
              <th scope="col">{t('accountGroups.list.status')}</th>
              <th scope="col">
                <span className="sr-only">{t('accountGroups.list.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {groups.map((group) => (
              <tr key={group.id}>
                <th scope="row">
                  <span>{group.label}</span>
                  <small>{t('accountGroups.list.depth', { depth: group.depth })}</small>
                </th>
                <td>
                  {group.parentId
                    ? (group.parentLabel ?? t('accountGroups.list.unavailableParent'))
                    : t('accountGroups.form.noParent')}
                </td>
                <td>
                  <ShareCell share={group.share} />
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
                <td className={styles.rowActions}>
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
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
