import type { AccountGroup } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';

interface GroupTreeActionsProps {
  group: AccountGroup;
  onArchive: (group: AccountGroup) => void;
  onEdit: (group: AccountGroup) => void;
}

export function GroupTreeActions({ group, onArchive, onEdit }: GroupTreeActionsProps) {
  const { t } = useTranslation();

  return (
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
  );
}
