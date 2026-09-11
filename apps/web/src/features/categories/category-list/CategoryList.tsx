import type { Category, CategoryLifecycleOperation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { CategoryIdentity } from '@/features/categories/category-identity/CategoryIdentity';
import { CategoryRedirection } from './category-redirection/CategoryRedirection';
import styles from './CategoryList.module.css';

const LIFECYCLE_ACTIONS: CategoryLifecycleOperation[] = ['MOVE', 'MERGE', 'REPLACE', 'ARCHIVE'];

const LIFECYCLE_ICONS = {
  ARCHIVE: 'archive',
  MERGE: 'merge',
  MOVE: 'move',
  REPLACE: 'replace',
} as const;

interface CategoryListProps {
  categories: Category[];
  onEdit: (category: Category) => void;
  onLifecycle: (category: Category, operation: CategoryLifecycleOperation) => void;
}

export function CategoryList({ categories, onEdit, onLifecycle }: CategoryListProps) {
  const { t } = useTranslation();

  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('categories.list.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('categories.fields.label')}</th>
              <th scope="col">{t('categories.fields.type')}</th>
              <th scope="col">{t('categories.fields.parent')}</th>
              <th scope="col">{t('categories.fields.axes')}</th>
              <th scope="col">{t('categories.list.redirection')}</th>
              <th scope="col">{t('categories.list.status')}</th>
              <th scope="col">
                <span className="sr-only">{t('categories.list.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {categories.map((category) => {
              const archived = category.archivedAt !== null;

              return (
                <tr key={category.id}>
                  <th scope="row">
                    <CategoryIdentity
                      color={category.color}
                      icon={category.icon}
                      label={category.label}
                    />
                  </th>
                  <td>{t(`categories.types.${category.type}`)}</td>
                  <td>
                    {category.parentId
                      ? (category.parentLabel ?? t('categories.list.unavailableParent'))
                      : t('categories.form.noParent')}
                  </td>
                  <td>
                    {category.defaultAnalyticAxes.length > 0
                      ? category.defaultAnalyticAxes
                          .map((axis) => t(`categories.axes.${axis}`))
                          .join(', ')
                      : t('categories.list.noAxes')}
                  </td>
                  <td>
                    <CategoryRedirection replacement={category.replacement} />
                  </td>
                  <td>
                    <StatusBadge tone={archived ? 'warning' : 'positive'}>
                      {t(archived ? 'categories.list.archived' : 'categories.list.active')}
                    </StatusBadge>
                  </td>
                  <td>
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
                          // Fifty rows carry fifty identical verbs; the accessible name
                          // has to say which category is about to be reorganised.
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
