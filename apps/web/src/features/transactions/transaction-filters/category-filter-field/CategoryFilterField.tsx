import type { Category } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import styles from './CategoryFilterField.module.css';

interface CategoryFilterFieldProps {
  categoryIds: readonly string[];
  includeDescendants: boolean;
  onAddCategory: (categoryId: string, category: Category | null) => void;
  onIncludeDescendantsChange: (value: boolean) => void;
}

/**
 * Adds one category at a time to the filter's category list, plus whether a matched category
 * also pulls in its descendants. The picker always starts empty: the already-added categories
 * live in the chip row above, not inside this field.
 */
export function CategoryFilterField({
  categoryIds,
  includeDescendants,
  onAddCategory,
  onIncludeDescendantsChange,
}: CategoryFilterFieldProps) {
  const { t } = useTranslation();

  return (
    <div className={styles.field}>
      <CategoryPicker
        label={t('transactions.filters.categoryLabel')}
        onChange={(categoryId, pickedCategory) => {
          if (categoryId && !categoryIds.includes(categoryId)) {
            onAddCategory(categoryId, pickedCategory ?? null);
          }
        }}
        placeholder={t('transactions.filters.addCategory')}
        value=""
      />
      <label className={styles.checkbox}>
        <input
          checked={includeDescendants}
          onChange={(event) => onIncludeDescendantsChange(event.target.checked)}
          type="checkbox"
        />
        <span>{t('transactions.filters.includeDescendants')}</span>
      </label>
    </div>
  );
}
