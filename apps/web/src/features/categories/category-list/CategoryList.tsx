import type { Category } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import styles from './CategoryList.module.css';

interface CategoryListProps {
  categories: Category[];
  onEdit: (category: Category) => void;
}

export function CategoryList({ categories, onEdit }: CategoryListProps) {
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
              <th scope="col">{t('categories.list.status')}</th>
              <th scope="col">
                <span className="sr-only">{t('categories.list.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {categories.map((category) => (
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
                  <StatusBadge tone={category.archivedAt ? 'warning' : 'positive'}>
                    {t(category.archivedAt ? 'categories.list.archived' : 'categories.list.active')}
                  </StatusBadge>
                </td>
                <td>
                  <button
                    className="secondary-action"
                    disabled={category.archivedAt !== null}
                    onClick={() => onEdit(category)}
                    type="button"
                  >
                    {t('categories.list.edit')}
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
