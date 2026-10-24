import type { Category, CategoryLifecycleOperation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { buildCategoryTree } from './buildCategoryTree';
import { CategoryTreeNode } from './CategoryTreeNode';
import styles from './CategoryTree.module.css';

interface CategoryTreeProps {
  categories: Category[];
  onEdit: (category: Category) => void;
  onLifecycle: (category: Category, operation: CategoryLifecycleOperation) => void;
}

export function CategoryTree({ categories, onEdit, onLifecycle }: CategoryTreeProps) {
  const { t } = useTranslation();

  return (
    <ul aria-label={t('categories.list.caption')} className={styles.branch}>
      {buildCategoryTree(categories).map((node) => (
        <CategoryTreeNode
          key={node.category.id}
          node={node}
          root
          onEdit={onEdit}
          onLifecycle={onLifecycle}
        />
      ))}
    </ul>
  );
}
