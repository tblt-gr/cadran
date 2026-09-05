import type { CategoryType } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';

interface ParentCategoryFieldProps {
  onChange: (parentId: string) => void;
  type: CategoryType;
  value: string;
}

/** The parent choice of the category form: a picker restricted to categories that can still take a child. */
export function ParentCategoryField({ onChange, type, value }: ParentCategoryFieldProps) {
  const { t } = useTranslation();

  return (
    <CategoryPicker
      emptyOptionLabel={t('categories.form.noParent')}
      label={t('categories.fields.parent')}
      onChange={onChange}
      parentEligible
      type={type}
      value={value}
    />
  );
}
