import type { ReactNode } from 'react';
import { canonicalCategoryColor } from '@/features/categories/category-color';
import { categoryIcon } from '@/features/categories/category-icons';
import { CategorySwatch } from '@/features/categories/category-swatch/CategorySwatch';
import styles from './CategoryLabel.module.css';

/**
 * A category name preceded by its mark, without the tag surface of CategoryIdentity: for legends
 * and lists where the row already is the surface. No icon nor colour draws no mark.
 */
export function CategoryLabel({
  color,
  icon,
  children,
}: {
  color?: string | null;
  icon?: string | null;
  children: ReactNode;
}) {
  const marked = Boolean(categoryIcon(icon) || canonicalCategoryColor(color));
  return (
    <span className={styles.label}>
      {marked ? <CategorySwatch color={color} icon={icon} /> : null}
      <span className={styles.text}>{children}</span>
    </span>
  );
}
