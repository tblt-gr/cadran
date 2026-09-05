import type { Category, CategoryLifecycleOperation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { CategoryRedirection } from './category-redirection/CategoryRedirection';
import styles from './CategoryList.module.css';

const LIFECYCLE_ACTIONS: CategoryLifecycleOperation[] = ['MOVE', 'MERGE', 'REPLACE', 'ARCHIVE'];

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
                    <span>{category.label}</span>
                    {category.color ? <small>{category.color}</small> : null}
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
                    <div className={styles.actions}>
                      <button
                        aria-label={t('categories.list.actionFor', {
                          action: t('categories.list.edit'),
                          label: category.label,
                        })}
                        className="secondary-action"
                        disabled={archived}
                        onClick={() => onEdit(category)}
                        type="button"
                      >
                        {t('categories.list.edit')}
                      </button>
                      {LIFECYCLE_ACTIONS.map((operation) => (
                        <button
                          // Fifty rows carry fifty identical verbs; the accessible name
                          // has to say which category is about to be reorganised.
                          aria-label={t('categories.list.actionFor', {
                            action: t(`categories.lifecycle.${operation}.action`),
                            label: category.label,
                          })}
                          className="secondary-action"
                          disabled={archived}
                          key={operation}
                          onClick={() => onLifecycle(category, operation)}
                          type="button"
                        >
                          {t(`categories.lifecycle.${operation}.action`)}
                        </button>
                      ))}
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
