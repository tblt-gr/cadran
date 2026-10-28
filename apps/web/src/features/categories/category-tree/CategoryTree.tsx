import type { Category } from '@cadran/api-client';
import type { CategoryDialogOperation } from '@/features/categories/category-lifecycle/CategoryLifecycleDialog';
import { useTranslation } from 'react-i18next';
import { buildTree } from '@/lib/tree/buildTree';
import { CategoryTreeNode } from './CategoryTreeNode';
import styles from './CategoryTree.module.css';

interface CategoryTreeProps {
  categories: Category[];
  onEdit: (category: Category) => void;
  onLifecycle: (category: Category, operation: CategoryDialogOperation) => void;
}

export function CategoryTree({ categories, onEdit, onLifecycle }: CategoryTreeProps) {
  const { t } = useTranslation();

  return (
    <ul aria-label={t('categories.list.caption')} className={styles.branch}>
      {buildTree(categories).map((node) => (
        <CategoryTreeNode
          key={node.item.id}
          node={node}
          root
          onEdit={onEdit}
          onLifecycle={onLifecycle}
        />
      ))}
    </ul>
  );
}
