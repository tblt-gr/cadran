import type { Category, CategoryLifecycleOperation } from '@cadran/api-client';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { CategoryIdentity } from '@/features/categories/category-identity/CategoryIdentity';
import { CategoryRedirection } from './category-redirection/CategoryRedirection';
import type { CategoryTreeNodeData } from './buildCategoryTree';
import { CategoryTreeActions } from './CategoryTreeActions';
import styles from './CategoryTree.module.css';

interface CategoryTreeNodeProps {
  node: CategoryTreeNodeData;
  /** An orphan root names the parent that is not part of the list. */
  root?: boolean;
  onEdit: (category: Category) => void;
  onLifecycle: (category: Category, operation: CategoryLifecycleOperation) => void;
}

export function CategoryTreeNode({
  node,
  onEdit,
  onLifecycle,
  root = false,
}: CategoryTreeNodeProps) {
  const { t } = useTranslation();
  const [expanded, setExpanded] = useState(false);
  const childrenId = useId();
  const { category, children } = node;
  const archived = category.archivedAt !== null;
  const axes = category.defaultAnalyticAxes.map((axis) => t(`categories.axes.${axis}`)).join(', ');

  return (
    <li className={styles.node}>
      <div className={styles.row} data-archived={archived}>
        {children.length > 0 ? (
          <button
            aria-controls={childrenId}
            aria-expanded={expanded}
            aria-label={t(expanded ? 'categories.tree.collapse' : 'categories.tree.expand', {
              count: children.length,
              label: category.label,
            })}
            className={`icon-ghost ${styles.toggle}`}
            data-expanded={expanded}
            onClick={() => setExpanded((current) => !current)}
            type="button"
          >
            <Icon name="chevron-right" size={16} />
          </button>
        ) : (
          <span aria-hidden="true" className={styles.toggleSpacer} />
        )}
        <div className={styles.summary}>
          <CategoryIdentity
            color={category.effectiveColor}
            icon={category.effectiveIcon}
            label={category.label}
          />
          <p className={styles.meta}>
            <span className="sr-only">{t('categories.tree.level', { depth: category.depth })}</span>
            {axes !== '' ? <span>{axes}</span> : null}
            {archived ? <span>{t('categories.list.archived')}</span> : null}
            {root && category.parentId !== null ? (
              <span>
                {t('categories.tree.parentOf', {
                  parent: category.parentLabel ?? t('categories.list.unavailableParent'),
                })}
              </span>
            ) : null}
            {category.replacement ? (
              <CategoryRedirection replacement={category.replacement} />
            ) : null}
          </p>
        </div>
        <CategoryTreeActions category={category} onEdit={onEdit} onLifecycle={onLifecycle} />
      </div>
      {children.length > 0 ? (
        <ul className={styles.branch} hidden={!expanded} id={childrenId}>
          {expanded
            ? children.map((child) => (
                <CategoryTreeNode
                  key={child.category.id}
                  node={child}
                  onEdit={onEdit}
                  onLifecycle={onLifecycle}
                />
              ))
            : null}
        </ul>
      ) : null}
    </li>
  );
}
