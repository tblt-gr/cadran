import type { Category, CategoryLifecycleOperation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';

const LIFECYCLE_ACTIONS: CategoryLifecycleOperation[] = ['MOVE', 'MERGE', 'REPLACE', 'ARCHIVE'];

const LIFECYCLE_ICONS = {
  ARCHIVE: 'archive',
  MERGE: 'merge',
  MOVE: 'move',
  REPLACE: 'replace',
} as const;

interface CategoryTreeActionsProps {
  category: Category;
  onEdit: (category: Category) => void;
  onLifecycle: (category: Category, operation: CategoryLifecycleOperation) => void;
}

export function CategoryTreeActions({ category, onEdit, onLifecycle }: CategoryTreeActionsProps) {
  const { t } = useTranslation();
  const archived = category.archivedAt !== null;

  return (
    <ActionMenu
      items={[
        {
          disabled: archived,
          icon: 'edit',
          id: 'edit',
          label: t('categories.list.actionFor', {
            action: t('categories.list.edit'),
            label: category.label,
          }),
          onSelect: () => onEdit(category),
          text: t('categories.list.edit'),
        },
        ...LIFECYCLE_ACTIONS.map((operation) => ({
          disabled: archived,
          icon: LIFECYCLE_ICONS[operation],
          id: operation,
          // Many rows carry the same verbs; the accessible name says which category is reorganised.
          label: t('categories.list.actionFor', {
            action: t(`categories.lifecycle.${operation}.action`),
            label: category.label,
          }),
          onSelect: () => onLifecycle(category, operation),
          text: t(`categories.lifecycle.${operation}.action`),
        })),
      ]}
      label={t('categories.list.openActions', { label: category.label })}
    />
  );
}
