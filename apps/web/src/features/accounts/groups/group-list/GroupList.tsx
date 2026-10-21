import { canonicalGroupColor } from '@/features/accounts/group-color';
import type { AccountGroup } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { SortableHeader } from '@/components/ui/sortable-header/SortableHeader';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { compareText, toggleSort, type SortDirection } from '@/lib/tableSort';
import styles from './GroupList.module.css';

type GroupSortColumn = 'label' | 'parent' | 'status';

interface GroupListProps {
  groups: AccountGroup[];
  onArchive: (group: AccountGroup) => void;
  onEdit: (group: AccountGroup) => void;
}

export function GroupList({ groups, onArchive, onEdit }: GroupListProps) {
  const { i18n, t } = useTranslation();
  const [sort, setSort] = useState<{ column: GroupSortColumn; direction: SortDirection }>({
    column: 'label',
    direction: 'asc',
  });
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
    switch (sort.column) {
      case 'label':
        return compareText(left.label, right.label, i18n.language, sort.direction);
      case 'parent':
        return compareText(parentLabel(left), parentLabel(right), i18n.language, sort.direction);
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
              return (
                <tr
                  className={styles.groupRow}
                  key={group.id}
                  style={{
                    borderInlineStartColor: canonicalGroupColor(group.resolvedColor) ?? undefined,
                  }}
                >
                  <th scope="row">
                    <span>{group.label}</span>
                    <small>{t('accountGroups.list.depth', { depth: group.depth })}</small>
                  </th>
                  <td>{parentLabel(group)}</td>
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
